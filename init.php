<?php
class af_refspoof extends Plugin
{
    private const STORAGE_ENABLED_FEEDS = 'feeds';
    private const STORAGE_ENABLED_DOMAINS = "enabled_domains";

    private $host;
    private $dbh;

    public function about()
    {
        return array(
            "1.0.4",
            "Fakes Referral on Images",
            "Alexander Chernov"
            );
    }

    public function init($host)
    {
        require_once("PhCURL.php");
        $this->host = $host;
        $this->dbh = Db::get();
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
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

        $configFeeds = $this->host->get($this, STORAGE_ENABLED_FEEDS);
        $feeds = $this->getFeeds();
        
        if (!count($feeds)) {
            return;
        }

        $title = __("Fake referral");
        $header = __("Enable referral spoofing based on the feed domain");
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
        $feeds  = $this->host->get($this, STORAGE_ENABLED_FEEDS);

        if (is_array($feeds) && in_array($feedId,array_keys($feeds))){
            $doc = new DOMDocument();
            @$doc->loadHTML($article['content']);
            if ($doc) {
                $xpath = new DOMXPath($doc);
                $entries = $xpath->query('(//img[@src])');
                /** @var $entry DOMElement **/
                $entry = null;
                $backendURL = 'backend.php?op=pluginhandler&method=redirect&plugin=af_refspoof';
                foreach ($entries as $entry){
                    $origSrc = $entry->getAttribute("src");
                    if ($origSrcSet = $entry->getAttribute("srcset")) {
                        $srcSet = preg_replace_callback('#([^\s]+://[^\s]+)#', function ($m) use ($backendURL, $article) {
                            return $backendURL . '&url=' . urlencode($m[0]) . '&ref=' . urlencode($article['link']);
                        }, $origSrcSet);

                        $entry->setAttribute("srcset", $srcSet);
                    }
                    $url = $backendURL . '&url=' . urlencode($origSrc) . '&ref=' . urlencode($article['link']);
                    $entry->setAttribute("src",$url);
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

    function redirect()
    {
        $client = new PhCURL($_REQUEST["url"]);
        $client->loadCommonSettings();
        $client->setReferer($_REQUEST["ref"]);
        $client->setUserAgent();

        $client->GET();
        ob_end_clean();
        //header_remove("Content-Type: text/json; charset=utf-8");
        header("Content-Type: ". $client->getContentType());
        echo $client->getData();
        exit(1);
    }
    function saveConfig()
    {
        $config = (array) $_POST['refSpoofFeed'];
        $this->host->set($this, STORAGE_ENABLED_FEEDS, $config);
        echo __("Configuration saved.");
    }
    protected function translate($msg){
        return __($msg);
    }
    /**
    * Find feeds from db
    *
    * @return array feeds
    */
    protected function getFeeds()
    {
        $feeds = array();
        $result = $this->dbh->query("SELECT id, title
                FROM ttrss_feeds
                WHERE owner_uid = ".$_SESSION["uid"].
                " ORDER BY order_id, title");
        while ($line = $this->dbh->fetch_assoc($result)) {
            $feeds[] = (object) $line;
        }
        return $feeds;
    }
    function api_version() {
        return 2;
    }

}
