<?php

namespace Outlandish\Wpackagist\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RollingCurl\Request as RollingRequest;
use RollingCurl\RollingCurl;
use Composer\Package\Version\VersionParser;
use Outlandish\Wpackagist\Package\Plugin;

class UpdateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('update')
            ->setDescription('Update version info for individual plugins')
            ->addOption(
                'concurrent',
                null,
                InputOption::VALUE_REQUIRED,
                'Max concurrent connections',
                '10'
            );
    }

    /**
     * Parse the $version => $tag from the developers tab of wordpress.org
     * Advantages:
     *   * Checks for invalid and inactive plugins (and ignore it until next SVN commit)
     *   * Use the parsing mechanism of wordpress.org, which is more robust
     *
     * Disadvantages:
     *   * Much slower
     *   * Subject to changes without notice
     *
     * Wordpress.org APIs do not list versions history
     * @link http://codex.wordpress.org/WordPress.org_API
     *
     * <li><a itemprop="downloadUrl" href="http://downloads.wordpress.org/plugin/PLUGIN.zip" rel="nofollow">Development Version</a> (<a href="http://plugins.svn.wordpress.org/PLUGIN/trunk" rel="nofollow">svn</a>)</li>
     * <li><a itemprop="downloadUrl" href="http://downloads.wordpress.org/plugin/PLUGIN.VERSION.zip" rel="nofollow">VERSION</a> (<a href="http://plugins.svn.wordpress.org/PLUGIN/TAG" rel="nofollow">svn</a>)</li>
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rollingCurl = new RollingCurl();
        $rollingCurl->setSimultaneousLimit((int) $input->getOption('concurrent'));

        /**
         * @var \PDO $db
         */
        $db = $this->getApplication()->getSilexApplication()['db'];

        $stmt = $db->prepare('UPDATE packages SET last_fetched = datetime("now"), versions = :json, is_active = 1 WHERE class_name = :class_name AND name = :name');
        $deactivate = $db->prepare('UPDATE packages SET last_fetched = datetime("now"), is_active = 0 WHERE class_name = :class_name AND name = :name');

        // get packages that have never been fetched or have been updated since last being fetched
        // or that are inactive but have been updated in the past 90 days and haven't been fetched in the past 7 days
        $plugins = $db->query('
            SELECT * FROM packages
            WHERE last_fetched IS NULL
            OR last_fetched < last_committed
            OR (is_active = 0 AND last_committed > date("now", "-90 days") AND last_fetched < datetime("now", "-7 days"))
        ')->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);

        $count = count($plugins);
        $versionParser = new VersionParser();

        $rollingCurl->setCallback(function (RollingRequest $request, RollingCurl $rollingCurl) use ($count, $stmt, $deactivate, $output, $versionParser) {
            $plugin = $request->getExtraInfo();

            $percent = $rollingCurl->countCompleted() / $count * 100;
            $output->writeln(sprintf("<info>%04.1f%%</info> Fetched %s", $percent, $plugin->getName()));

            if ($request->getResponseErrno()) {
                $output->writeln("<error>Error while fetching ".$request->getUrl()." (".$request->getResponseError().")"."</error>");

                return;
            }

            $info = $request->getResponseInfo();
            if ($info['http_code'] != 200) {
                // Plugin is not active
                $deactivate->execute(array(':class_name' => get_class($plugin), ':name' => $plugin->getName()));

                return;
            }

            $dom = new \DOMDocument('1.0', 'UTF-8');
            // WP.org generates some parsing errors, ignore them
            @$dom->loadHTML($request->getResponseText());

            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//div[@id="plugin-info"]//a[contains(., "svn")]');
            $versions = array();

            for ($i = 0; $i < $nodes->length; $i++) {
                $node = $nodes->item($i);
                $href = rtrim($node->getAttribute('href'), '/');

                if (preg_match('/\/trunk$/', $href)) {
                    $tag = 'trunk';
                } elseif (preg_match('/\/((?:tags\/)?([^\/]+))$/', $href, $matches)) {
                    $tag = $matches[1];
                } else {
                    continue;
                }

                $download = $xpath->query('../a[contains(@href, ".zip")]', $node);
                if ($download->length) {
                    $_version = $download->item(0)->textContent;
                    $_version = trim($_version, ' ()');
                    if (preg_match('/development/i', $_version)) {
                        $version = 'dev-trunk';
                    } else {
                        try {
                            $versionParser->normalize($_version);
                            $version = $_version;
                        } catch (\UnexpectedValueException $e) {
                            continue;
                        }
                    }
                } else {
                    continue;
                }

                $versions[$version] = $tag;

                // Version points directly to trunk
                // Add dev-trunk => trunk to make sure it exists
                if ($tag == 'trunk') {
                    $versions['dev-trunk'] = 'trunk';
                }
            }

            if (!isset($versions['dev-trunk']) && $plugin instanceof Plugin) {
                // plugins always have at least trunk
                $versions['dev-trunk'] = 'trunk';
            }

            if ($versions) {
                $stmt->execute(array(':class_name' => get_class($plugin), ':name' => $plugin->getName(), ':json' => json_encode($versions)));
            } else {
                $deactivate->execute(array(':class_name' => get_class($plugin), ':name' => $plugin->getName()));
            }

            // recoup some memory
            $request->setResponseText(null);
            $request->setResponseInfo(null);
        });

        foreach ($plugins as $plugin) {
            $request = new RollingRequest($plugin->getHomepageUrl().'developers/');
            $request->setExtraInfo($plugin);
            $rollingCurl->add($request);
        }

        //fix outdated CA issue on Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $rollingCurl->addOptions(array(CURLOPT_CAINFO => "data/cacert.pem"));
        }

        $rollingCurl->execute();
    }
}
