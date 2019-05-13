<?php
class af_refspoof extends Plugin
{
    private const STORAGE_ENABLED_FEEDS = 'feeds';
    private const STORAGE_ENABLED_DOMAINS = "enabled_domains";

    private $host;

    public function about()
    {
        return array(
            1.1,
            "Fakes Referral on Images",
            "Alexander Chernov"
            );
    }

    public function init($host)
    {
        $this->host = $host;
        $this->host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $this->host->add_hook($host::HOOK_PREFS_TAB, $this);
        $this->host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $this->host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
    }

    public function hook_prefs_edit_feed($feedId)
    {
        $enabledFeeds = $this->host->get($this, STORAGE_ENABLED_FEEDS, array());
        $checked = array_key_exists($feedId, $enabledFeeds) ? "checked" : "";
        $title = __("Fake referral");
        $label = __('Fake referral for this feed');

        echo <<<EOF
<header>{$title}</header>
<section>
    <fieldset>
        <input dojoType="dijit.form.CheckBox" type="checkbox" id="af_refspoof_enabled"
            name="af_refspoof_enabled" {$checked}>
        <label class="checkbox" for="af_refspoof_enabled">
            {$label}
        </label>
    </fieldset>
</section>
EOF;
    }

    public function hook_prefs_tab($args)
    {
        if ($args !== "prefFeeds") {
            return;
        }

        $title = __("Fake referral");
        $header = __("Enable referral spoofing based on the feed domain (enter one domain per line)");
        $enabledDomains = implode("\n", $this->host->get($this, STORAGE_ENABLED_DOMAINS, ""));
        $button = __("Save");

        echo <<<EOT
<div data-dojo-type="dijit/layout/ContentPane" title="<i class='material-icons'>image</i> {$title}"
    style="display:flex;flex-direction:column;">
    <h3>{$header}</h3>
    
    <form data-dojo-type="dijit/form/Form" style="flex-grow:1;display:flex;flex-direction:column;">
        <input type="hidden" data-dojo-type="dijit/form/TextBox" name="op" value="pluginhandler">
        <input type="hidden" data-dojo-type="dijit/form/TextBox" name="plugin" value="af_refspoof">
        <input type="hidden" data-dojo-type="dijit/form/TextBox" name="method" value="saveDomains">
        <script type="dojo/method" event="onSubmit" args="evt">
            evt.preventDefault();
            if (this.validate()) {
                new Ajax.Request('backend.php', {
                    parameters: dojo.objectToQuery(this.getValues()),
                    onLoading: function() {
                        Notify.progress("Saving...");
                    },
                    onComplete: function(transport) {
                        Notify.info(transport.responseText);
                    }
                });
            }
        </script>

        <textarea id="af_domains" name="af_domains" data-dojo-type="dijit/form/SimpleTextarea"
            style="box-sizing:border-box;width:50%;height:100%;">{$enabledDomains}</textarea>
        <button data-dojo-type="dijit/form/Button" type="submit">{$button}</button>
    </form>
</div>
EOT;
    }

    public function hook_prefs_save_feed($feedId)
    {
        $enabledFeeds = $this->host->get($this, STORAGE_ENABLED_FEEDS, array());
        
        if (checkbox_to_sql_bool($_POST["af_refspoof_enabled"])) {
            $enabledFeeds[$feedId] = 1;
        } else {
            if (array_key_exists($feedId, $enabledFeeds)) {
                unset($enabledFeeds[$feedId]);
            }
        }

        $this->host->set($this, STORAGE_ENABLED_FEEDS, $enabledFeeds);
    }

    public function hook_render_article_cdm($article)
    {
        $feedId = $article['feed_id'];
        $enabledFeeds  = $this->host->get($this, STORAGE_ENABLED_FEEDS, array());

        if (array_key_exists($feedId, $enabledFeeds) || $this->isDomainEnabled($article["site_url"])) {
            $doc = new DOMDocument();
            @$doc->loadHTML($article['content']);
            if ($doc !== false) {
                $xpath = new DOMXPath($doc);
                $entries = $xpath->query("(//img[@src])");
                $entry = null;
                $backendURL = 'backend.php?op=pluginhandler&method=proxy&plugin=af_refspoof';
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

    public function saveDomains()
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
        $this->host->set($this, STORAGE_ENABLED_DOMAINS, $domains);
        echo __("Domains saved");
    }

    public function proxy()
    {
        $userAgent = "Mozilla/5.0 (Windows NT 6.0; WOW64; rv:66.0) Gecko/20100101 Firefox/66.0";
        $curl = curl_init($_REQUEST["url"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curl, CURLOPT_REFERER, $_REQUEST["ref"]);
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        $data = curl_exec($curl);
        header("Content-Type: ". curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        echo $data;
    }

    public function api_version()
    {
        return 2;
    }

    private function isDomainEnabled($feedUri)
    {
        $enabledDomains = $this->host->get($this, STORAGE_ENABLED_DOMAINS, array());
        $host = parse_url($feedUri, PHP_URL_HOST);

        if (strpos($host, "www.") === 0 && in_array(str_replace("www.", "", $host), $enabledDomains)) {
            return true;
        }
        return in_array($host, $enabledDomains);
    }
}
