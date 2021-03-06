<?php
namespace Analysis;

require_once 'novutec/WhoisParser/Parser.php';
require_once 'novutec/DomainParser/Parser.php';
require_once 'novutec/TypoSquatting/Typo.php';

class Analyzer
{
    private $fb_stats = null;
    /**
     * @var Page $page
     */
    private $page;

    public function setPage(Page $page)
    {
        $this->page = $page;
    }

    public function getPage()
    {
        return $this->page;
    }

    public function getServerAllowsDirectoryBrowsing()
    {
        $content = $this->getPage()->getContent();
        if (strpos('<h1>Index of /', $content) !== false && strpos('Parent  Directory', $content) !== false) return true;
        //@hack to access google results
        ini_set('user_agent', 'Nokia6230/2.0 (04.12) MIDP-2.0 CLDC-1.1');
        $url = 'http://google.com/search?q=site:'.$this->getPage()->getUrl().'+Index+of';
        $gp = new Page();
        $gp->setUrl($url);
        $google_content = $gp->getContent();
        if (strpos('Parent  Directory', $google_content) !== false) return true;
        return false;
    }

    public function getIframeRenderingProtectionHeaderValue()
    {
        $rh = $this->getPage()->getResponseHeaders();
        return isset($rh['x-frame-options']) ? $rh['x-frame-options'] : null;
    }

    public function getSimilarTyposDomainsWithAvailability(){
        $res = array();
        foreach($this->getSimilarTyposDomains() as $domain) {
            $res[$domain] = $this->isDomainAvailableForRegistration($domain);
        }
        return $res;
    }

    public function getSimilarTldsDomainsWithAvailability(){
        $res = array();
        foreach($this->getSimilarTldsDomains() as $domain) {
            $res[$domain] = $this->isDomainAvailableForRegistration($domain);
        }
        return $res;
    }

    private function isDomainAvailableForRegistration($domain){

        if (checkdnsrr($domain.'.', 'ANY')) return false;
        $whois = $this->getWhois($domain);
        if ($whois['nameserver'] || $whois['expires'] || $whois['contacts']) return false;
        return true;
    }

    private function getPopularDomainTlds(){
        return array(
            '.com',
            '.net',
            '.org',
            '.us',
            '.biz',
            '.info',
        );
    }

    private function getSimilarTyposDomains(){
        $domain = $this->getPage()->getSldTld();
        $tld = $this->getPage()->getDomainTld(false);

        $Typo = new \Novutec\TypoSquatting\Typo;
        $Typo->setFormat('array');
        $result = $Typo->lookup($domain, $tld);
        return $result['typosByMissedLetters'];
    }

    private function getSimilarTldsDomains(){

        $sld = $this->getPage()->getDomainSld();
        $ctld = $this->getPage()->getDomainTld();
        $popular = $this->getPopularDomainTlds();
        $res = array();
        foreach($popular as $tld) {
            if($tld == $ctld) continue;
            $res[] = $sld.$tld;
        }
        return $res;
    }

    public function getMobileLoadTime(){
        ini_set('user_agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 5_0 like Mac OS X) AppleWebKit/534.46 (KHTML, like Gecko) Version/5.1 Mobile/9A334 Safari/7534.48.3');
        $start = microtime(true);
        $this->getPage()->getContent();
        $diff = microtime(true) - $start;
        return round($diff * 1000);
    }

    public function getMobileHasCss(){
        $dom = $this->getPage()->getSimpleHtmlDomObject();
        $screens = $dom->find('meta[content*=max-width], meta[content*=max-device-width]');
        foreach ($screens as $screen) {
            if(preg_match_all('|max-(device-)?width:\s*(\d+)px|', $screen->content, $matches)) {
                foreach ($matches[2] as $match) {
                    if ($match < 501) return true;
                }
            }

        }
        return false;
    }

    public function getMobileHasAppleIcon(){

        $simple_dom = $this->getPage()->getSimpleHtmlDomObject();
        $node = $simple_dom->find('link[rel=apple-touch-icon]', 0);

        if ($node && $node->href) return true;
        return false;
    }

    public function getMobileHasMetaViewportTag(){
        $simple_dom = $this->getPage()->getSimpleHtmlDomObject();
        $node = $simple_dom->find('meta[name=viewport]', 0);

        if ($node && $node->content) return true;
        return false;
    }

    public function getMobileHasRedirection(){
        return $this->getPage()->getRedirection() != null;
    }

    public function getRankAlexa()
    {
        $SEOstats = new \SEOstats\SEOstats($this->getPage()->getUrl());
        return $SEOstats->Alexa()->getGlobalRank();
    }

    public function getSocialFacebookStats()
    {
        if ($this->fb_stats) return $this->fb_stats;
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $query = "select total_count,like_count,comment_count,share_count,click_count from link_stat where url='{$url}'";
        $call = "https://api.facebook.com/method/fql.query?query=" . rawurlencode($query) . "&format=json";
        $p->setUrl($call);
        $data = json_decode($p->getContent());
        return $this->fb_stats = $data[0];
    }

    public function getSocialFacebookLikes(){
        $stats = $this->getSocialFacebookStats();
        return $stats->like_count?:0;
    }

    public function getSocialFacebookComments(){
        $stats = $this->getSocialFacebookStats();
        return $stats->comment_count?:0;
    }

    public function getSocialFacebookShares(){
        $stats = $this->getSocialFacebookStats();
        return $stats->share_count?:0;
    }

    public function getSocialDelicious(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'http://feeds.delicious.com/v2/json/urlinfo/data?url='.rawurlencode($url);
        $p->setUrl($call);
        $data = json_decode($p->getContent());
        return $data[0]->total_posts?:0;
    }

    public function getSocialGplus(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'https://plusone.google.com/_/+1/fastbutton?url='.rawurlencode($url);
        $p->setUrl($call);
        //@hack, as google+ does not support showing count without api key
        preg_match( '/window\.__SSR = {c: ([\d]+)/', $p->getContent(), $matches );

        if( isset( $matches[0] ) )
            return (int) str_replace( 'window.__SSR = {c: ', '', $matches[0] );
        return 0;
    }

    public function getSocialLinkedin(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'http://www.linkedin.com/countserv/count/share?format=json&url='.rawurlencode($url);
        $p->setUrl($call);
        $data = json_decode($p->getContent());
        return $data->count?:0;
    }

    public function getSocialTweets(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'http://urls.api.twitter.com/1/urls/count.json?url='.rawurlencode($url);
        $p->setUrl($call);
        $data = json_decode($p->getContent());
        return $data->count?:0;
    }

    public function getSocialStumbleupon(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'http://www.stumbleupon.com/services/1.01/badge.getinfo?url='.rawurlencode($url);
        $p->setUrl($call);
        $data = json_decode($p->getContent());
        return $data->result->views?$data->result->views:0;
    }

    public function getSocialPinterest(){
        $url = $this->getPage()->getUrl();
        $p = new Page();
        $call = 'http://api.pinterest.com/v1/urls/count.json?callback=receiveCount&url='.rawurlencode($url);
        $p->setUrl($call);
        $data = json_decode(substr($p->getContent(), 13, -1));
        return $data->count?:0;
    }

    public function pageContainsEmails()
    {
        $results = array();
        $content = $this->getPage()->getContent();
        $contains = preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}\b/si', $content, $results);
        return $contains;
    }

    public function getBacklinksGoogle()
    {
        $url = $this->getPage()->getUrl();
        $backlinks = \SEOstats\Services\Google::getBacklinksTotal($url);
        return $backlinks;
    }

    public function getIndexedPagesGoogle()
    {
        $url = $this->getPage()->getUrl();
        $index = \SEOstats\Services\Google::getSearchResultsTotal($url);
        return $index;
    }

    public function getRankPagerank()
    {
        $url = $this->getPage()->getUrl();
        $rank = \SEOstats\Services\Google::getPageRank($url);
        return $rank;
    }

    public function getDomainExpirationDate()
    {
        $domain = $this->getPage()->getSldTld();

        $Parser = $this->_getWhoisParser();
        $Parser->setFormat('array');
        $result = $Parser->lookup($domain);
        return $result['expires'];
    }

    public function getDomainRegistrationDate()
    {
        $domain = $this->getPage()->getSldTld();

        $Parser = $this->_getWhoisParser();
        $Parser->setFormat('array');
        $result = $Parser->lookup($domain);
        return $result['created'];
    }

    public function isPageUrlUserFriendly()
    {
        $url = $this->getPage()->getUrl();
        if (strpos($url, '?') !== false || strpos($url, '&') !== false || strpos($url, '=') !== false || strpos($url, '.php') !== false) return false;
        return true;
    }

    public function hasFlash()
    {
        $page = $this->getPage();
        $simple_dom = $page->getSimpleHtmlDomObject();
        $node = $simple_dom->find('object');
        return !empty($node);
    }

    public function getPageSize()
    {
        $content = $this->getPage()->getContent();
        return round((strlen($content) * 8) / 1024, 2);
    }

    public function getFaviconUrl()
    {
        $domain = $this->getPage()->getDomainLink();
        $favicon = $domain.'/favicon.ico';

        $simple_dom = $this->getPage()->getSimpleHtmlDomObject();
        $node = $simple_dom->find('link[rel=icon], link[rel=shortcut], link[rel="shortcut icon"]', 0);
        if ($node && $node->href) $favicon = strpos($node->href, '//')!==false?$node->href:$domain.($node->href[0] != '/'?'/':'').$node->href;
        if ($favicon[0] == '/') $favicon = 'http:'.$favicon;
        if($this->urlExists($favicon)) {
            return $favicon;
        }

        return false;
    }

    public function urlExists($url)
    {
        try {
            $browser = new \Buzz\Browser();
            $response = $browser->get($url);
            $bool = $response->isOk();
        } catch (\Exception $e) {
            error_log($e);
            $bool = false;
        }

        return $bool;
    }

    public function getWhois()
    {
        $domain = $this->getPage()->getSldTld();

        $Parser = $this->_getWhoisParser();
        $Parser->setFormat('array');
        $result = $Parser->lookup($domain);

//        $result = $Parser->lookup($ipv4);
//        $result = $Parser->lookup($ipv6);
//        $result = $Parser->lookup($asn);

        return $result;
    }

    public function getServerIpLocation()
    {
        $ip = gethostbyname($this->getPage()->getDomainName());
        $p = new Page();
        $p->setUrl('http://freegeoip.net/json/'.$ip);
        $data = json_decode($p->getContent(), true);
        return $data;
    }

    public function isIpSpammer()
    {
        $page = $this->getPage();
        $ip = gethostbyname($page->getDomainName());
        $p = new Page();
        $p->setUrl('http://www.stopforumspam.com/api?ip='.$ip);
        $xml = simplexml_load_string($p->getContent());
        return $xml->appears == 'yes';
    }

    public function getMetaKeywords()
    {
        $dom = $this->getPage()->getSimpleHtmlDomObject();
        $keywords = $dom->find('meta[name=keywords]');
        return isset($keywords->content) ? $keywords->content : null;
    }

    public function getTextHtmlRatio()
    {
        $page = $this->getPage();
        $content = $page->getContent();
        $text = $page->getTextContent();
        $ratio = strlen($content)/strlen($text)?:1;
        $ratio = round($ratio, 2);
        return $ratio;
    }

    public function getW3CValidator()
    {
        $p = new Page();
        // @hack to access w3c
        ini_set('user_agent', 'Mozilla/5.0 (PHP Analyzer) Version 1.0');
        $p->setUrl('http://validator.w3.org/check?uri='.$this->getPage()->getUrl());

        return $p;
    }

    public function getTagCloud($limit = 10)
    {
        $text = $this->getPage()->getTextContent();
        $text = mb_strtolower($text);
        preg_match_all("/[^\s^0-9.;:\/()!?$#@%*_`~|\-,]{4,}/", $text, $matches);
        $values = array_count_values($matches[0]);
        arsort($values);
        $result = array_slice($values, 0, $limit);
        return $result;
    }

    public function getHeaders()
    {
        $full_headers = array();
        $headers = $this->getPage()->getResponseHeaders();
        foreach ($headers as $header) {
            if(!strpos($header, ':')) {
                continue;
            }
            list($key, $val) = explode(': ', $header, 2);
            $full_headers[$key] = $val;
        }
        return $full_headers;
    }

    public function getServer()
    {
        $server_headers = $this->getHeaders();
        if(isset($server_headers['Server'])) {
            return $server_headers['Server'];
        }

        if(isset($server_headers['X-Powered-By'])) {
            return $server_headers['X-Powered-By'];
        }

        return null;
    }

    public function containsAnalytics()
    {
        $content = $this->getPage()->getContent();
        return strpos($content,'pageTracker._trackPageview();') !== false || strpos($content, "'.google-analytics.com/ga.js';") !== false;
    }

    private function _getWhoisParser()
    {
        return new \Novutec\WhoisParser\Parser();
    }
}