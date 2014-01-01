<?php
$username = "MirrorBot_";
$password = file_get_contents('password.txt');

$excludedDomains = array( //domains that aren't worth checking - rarely down or block the bot's requests
'imgur.com', 'i.imgur.com', 'reddit.com', 'livememe.com', 'youtube.com', 'youtu.be',
'cdn.theatlantic.com', 'en.wikipedia.org', 'twitter.com', 'washingtonpost.com', 'news.com.au'
);
$excludedExtensions = array('jpg', 'png', 'gif'); //don't check image URLs - usually they aren't available on the common mirror sites

$postsToCheck = 1000; //Total number of posts to read from /r/all
$postsPerPage = 100; //Number of posts to load at once
$sleepTime = 2; //Time to wait between pages

date_default_timezone_set('America/New_York');
function d($m) {
    $m = '['.date(DATE_RFC2822).'] '.$m."\n";
    echo $m;
    fwrite($GLOBALS['log'], $m);
}
function check($url) {
    d('Checking ' .$url);
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_SSL_VERIFYPEER => false, //skip verifying SSL - unnecessary for our purposes
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_NOBODY => true, //HEAD request
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.57 Safari/537.36'
    ));
    $result = curl_exec($ch);
    $error = false;
    $error_code = 0;
    if($result === false) {
        $error = curl_error($ch);
        $error_code = curl_errno($ch);
    }
    else {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($code == 404) //this used to handle all errors, but there were many poorly configured servers returning various error codes for all HEAD requests. TODO maybe send a regular GET request if this happens?
            $error = 'Not Found';
    }
        
    if($error) {
        if($error_code == 47) //ignore "too many redirects" - also happened on some sites that didn't handle HEAD requests properly
            return false;
        else {
            d($url.' is down: '.$error);
            return $error;
        }
    }
    else
        return false;
}

$log = fopen("log.txt", "a");
$downURLsFile = fopen("downURLs.txt", "a");

$downURLs = explode("\n", file_get_contents("downURLs.txt"));

require_once("reddit.php");
$reddit = new reddit($username, $password);

while(true) {
    $pages = $postsToCheck/$postsPerPage;
    $after = '';
    $urls = array();
    for($p = 0; $p < $pages; $p++) {
        d('Getting '.$postsPerPage.' posts after ['.$after.']');
        $posts = $reddit->getListing('all', $postsPerPage, $after);
        $posts = $posts->data->children;
        if($posts == null || count($posts) == 0) {
            d('Rate limiting or reddit is down, waiting 30 seconds to try again');
            sleep(30);
        }
        else {
            foreach($posts as $post) {
                $extension = substr($post->data->url, -3);
                if($post->data->is_self == null && !in_array($post->data->domain, $excludedDomains) && !in_array($extension, $excludedExtensions))
                    $urls[$post->data->id] = $post->data->url;
            }
            $after = 't3_'.($posts[count($posts) - 1]->data->id);
            sleep($sleepTime);
        }
    }

    d('Need to check '.count($urls).' URLs');
    $errors = array();
    foreach($urls as $postID => $url) {
        $result = check($url);
        if($result) {
            $errors[$postID] = array($url, $result);
        }
    }

    d('Need to post '.count($errors).' comments');
    foreach($errors as $postID => $array) {
        $plain_url = $array[0];
        $url = urlencode($array[0]);
        $error = $array[1];
        
        if(!in_array($plain_url, $downURLs)) {
            
            $comment = "Hi! I just checked this URL and it appeared to be unavailable or slow loading ($error). Here are some mirrors to try:

* [**Google Cache**](http://webcache.googleusercontent.com/search?q=cache:$url) [[text only](http://webcache.googleusercontent.com/search?strip=1&q=cache:$url)]
* [**Internet Archive**](http://web.archive.org/web/$plain_url)
* [**Coral Cache**](http://redirect.nyud.net/?url=$url)
* [**Bing Cache**](http://www.bing.com/search?q=$url)

*[^(Report Problem)](http://www.reddit.com/message/compose/?to=Pandalism) ^| [^(Source Code)](https://github.com/buildist/MirrorBot)*";
            $reddit->addComment('t3_'.$postID, urlencode($comment));
            d('Posted comment on '.$postID);
            $downURLs[] = $plain_url;
            fwrite($downURLsFile, $plain_url."\n");
            sleep(30);
        }
        else
            d('Not commenting on '.$postID);
    }
    sleep(15*60);
}
?>