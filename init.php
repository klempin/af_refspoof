<?php
class af_refspoof extends Plugin
{
    private const STORAGE_ENABLED_FEEDS = 'feeds';
    private const STORAGE_ENABLED_DOMAINS = "enabled_domains";

    private $host;

    public function about()
    {
        return array(
            null,
            "Fakes referral header on images",
            "Alexander Chernov",
            false,
            "https://github.com/klempin/af_refspoof"
        );
    }

    public function csrf_ignore($method)
    {
        if ($method === "proxy") {
            return true;
        }

        return false;
    }

    public function api_version()
    {
        return 2;
    }

    public function init($host)
    {
        $this->host = $host;
        $this->host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $this->host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $this->host->add_hook($host::HOOK_PREFS_TAB, $this);
        $this->host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
    }

    public function hook_prefs_edit_feed($feedId)
    {
        $enabledFeeds = $this->host->get($this, static::STORAGE_ENABLED_FEEDS, array());
        $checked = array_key_exists($feedId, $enabledFeeds) ? " checked" : "";
        $title = __("Fake referral");
        $label = __('Fake referral for this feed');

        echo <<<EOF
<header>{$title}</header>
<section>
    <fieldset>
        <input dojoType="dijit.form.CheckBox" type="checkbox" id="af_refspoof_enabled" name="af_refspoof_enabled"{$checked}>
        <label class="checkbox" for="af_refspoof_enabled">
            {$label}
        </label>
    </fieldset>
</section>
EOF;
    }

    public function hook_prefs_save_feed($feedId)
    {
        $enabledFeeds = $this->host->get($this, static::STORAGE_ENABLED_FEEDS, array());

        if (checkbox_to_sql_bool($_POST["af_refspoof_enabled"] ?? false)) {
            $enabledFeeds[$feedId] = $feedId;
        } else {
            unset($enabledFeeds[$feedId]);
        }

        $this->host->set($this, static::STORAGE_ENABLED_FEEDS, $enabledFeeds);
    }

    public function hook_prefs_tab($args)
    {
        if ($args !== "prefFeeds") {
            return;
        }

        $title = __("Fake referral");
        $heading = __("Enable referral spoofing based on the feed domain (enter one domain per line)");
        $enabledDomains = implode("\n", $this->host->get($this, static::STORAGE_ENABLED_DOMAINS, array()));
        $pluginHandlerTags = \Controls\pluginhandler_tags($this, "save_domains");
        $submitTag = \Controls\submit_tag(__("Save"));

        echo <<<EOT
<div dojoType="dijit.layout.AccordionPane" title="<i class='material-icons'>image</i> {$title}">
    <h3>{$heading}</h3>

    <form dojoType='dijit.form.Form'>
        {$pluginHandlerTags}
        <script type="dojo/method" event="onSubmit" args="evt">
            evt.preventDefault();
            if (this.validate()) {
                Notify.progress('Saving data...', true);
                xhr.post("backend.php", this.getValues(), (reply) => {
                    Notify.info(reply);
                })
            }
        </script>

        <fieldset>
            <textarea id="af_domains" name="af_domains" data-dojo-type="dijit/form/SimpleTextarea" style="height:400px;box-sizing:border-box;">{$enabledDomains}</textarea>
        </fieldset>

        <fieldset>
            {$submitTag}
        </fieldset>
    </form>
</div>
EOT;
    }

    public function hook_render_article_cdm($article)
    {
        $enabledFeeds  = $this->host->get($this, static::STORAGE_ENABLED_FEEDS, array());

        if (array_key_exists($article['feed_id'], $enabledFeeds) || $this->isDomainEnabled($article["site_url"])) {
            $doc = new DOMDocument();
            @$doc->loadHTML($article['content']);
            if ($doc !== false) {
                $xpath = new DOMXPath($doc);
                $entries = $xpath->query("(//img[@src])");
                $backendURL = Config::get_self_url() . '/backend.php?op=pluginhandler&method=proxy&plugin=af_refspoof';

                foreach ($entries as $entry) {
                    $origSrc = $entry->getAttribute("src");
                    if ($origSrcSet = $entry->getAttribute("srcset")) {
                        $srcSet = preg_replace_callback('#([^\s]+://[^\s]+)#', function ($m) use ($backendURL, $article) {
                            return $backendURL . '&url=' . urlencode($m[0]) . '&ref=' . urlencode($article['link']);
                        }, $origSrcSet);
                        $entry->setAttribute("srcset", $srcSet);
                    }
                    $url = $backendURL . '&url=' . urlencode($origSrc) . '&ref=' . urlencode($article['link']);
                    $entry->setAttribute("src", $url);
                }
                $article["content"] = $doc->saveXML();
            }
        }
        return $article;
    }

    public function proxy()
    {
        $url = parse_url($_REQUEST["url"]);
        $ref = parse_url($_REQUEST["ref"]);
        $requestUri = "";

        if (strpos($_REQUEST["url"], "/") === 0) {
            $requestUri .= ($ref["scheme"] ?? "http") . ":";

            if (strpos($_REQUEST["url"], "//") !== 0) {
                $requestUri .= "/";
            }
        }

        $requestUri .= $_REQUEST["url"];
        $filename = basename($requestUri);
        $userAgent = "Mozilla/5.0 (Windows NT 6.0; WOW64; rv:66.0) Gecko/20100101 Firefox/66.0";

        $curl = curl_init($requestUri);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_REFERER, $_REQUEST["ref"]);
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        $curlData = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);

        if ($_REQUEST["origin_info"] ?? false) {
            header("Content-Type: text/plain");
            echo "Request url:                  " . $_REQUEST["url"] . "\n";
            echo "Request url after processing: " . $requestUri . "\n";
            echo "Referrer url:                 " . $_REQUEST["ref"] . "\n\n";
            echo "CURL information:\n";
            print_r($curlInfo);
            echo "\nCURL data:\n";
            echo $curlData;

        } else if ($curlInfo["http_code"] ?? false === 200) {
            if ($url["path"] ?? null !== null) {
                header('Content-Disposition: inline; filename="' . basename($url["path"]) . '"');
            }
            header("Content-Type: " . $curlInfo["content_type"]);
            echo $curlData;

        } {
            http_response_code($curlInfo["http_code"]);
        }
    }

    public function save_domains()
    {
        if (!isset($_POST["af_domains"])) {
            echo __("No domains saved");
            return;
        }
        $domains = explode("\n", str_replace("\r", "", $_POST["af_domains"]));
        foreach ($domains as $key => $value) {
            if (strlen($value) < 1) {
                unset($domains[$key]);
            }
        }
        $this->host->set($this, static::STORAGE_ENABLED_DOMAINS, $domains);
        echo __("Domains saved");
    }

    private function isDomainEnabled($feedUri)
    {
        $enabledDomains = $this->host->get($this, static::STORAGE_ENABLED_DOMAINS, array());
        $host = parse_url($feedUri, PHP_URL_HOST);

        if (strpos($host, "www.") === 0 && in_array(str_replace("www.", "", $host), $enabledDomains)) {
            return true;
        }
        return in_array($host, $enabledDomains);
    }
}
