<?
session_start();
date_default_timezone_set(file_get_contents("http://rhiaro.co.uk/tz"));
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header("Location: /morph"); }
if(isset($_GET['reset'])){ unset($_SESSION[$_GET['reset']]); }

$base = "https://apps.rhiaro.co.uk/morph";


function discover_endpoint($url, $rel="micropub"){
  if(isset($_SESSION[$rel])){
    return $_SESSION[$rel];
  }else{
    $res = head_http_rels($url);
    $rels = $res['rels'];
    if(!isset($rels[$rel][0])){
      $parsed = json_decode(file_get_contents("https://pin13.net/mf2/?url=".$url), true);
      if(isset($parsed['rels'])){ $rels = $parsed['rels']; }
    }
    if(!isset($rels[$rel][0])){
      // TODO: Try in body
      return "Not found";
    }
    $_SESSION[$rel] = $rels[$rel][0];
    return $rels[$rel][0];
  }
}

function context(){
  return array(
      "@context" => array("http://www.w3.org/ns/activitystreams#")
    );
}

function get_feed(){
  
  $source = urldecode($_SESSION['url']);
  $ch = curl_init($source);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json"));
  $response = curl_exec($ch);
  curl_close($ch);
  $collection = json_decode($response, true);
  return $collection;

}

function is_image($item){
  if(isset($item["type"]) && $item["type"] == "Photo"){
    return true;
  }
  $imgs = array("jpg", "jpeg", "png", "gif");
  $path = explode("/", $item["id"]);
  $id = array_pop($path);
  $fn = explode(".", $id);
  $ext = strtolower(array_pop($fn));
  if(in_array($ext, $imgs)){
    return true;
  }
  return false;
}


function id_from_object($object){
  return $object["id"];
}
function arrayids_to_string($array){
  $flat = array_map("id_from_object", $array);
  return implode(",", $flat);
}

function url_to_objectid($url){
  return array("id" => trim($url));
}
function url_strings_to_array($urls){
  $ar = explode(",", $urls);
  return array_map("url_to_objectid", $ar);
}

function form_to_update($post){
  $context = context();
  $type = array("type" => "Update");
  $data = array_merge($context, $type);
  $data['name'] = "Updated an object";
  $data['published'] = date(DATE_ATOM);
  $data['object'] = $post;
  unset($data['object']['submit']);
  
  // TODO: Should really handle empty values on the server end I think. 
  //       ie. It shouldn't set new attributes on the server it receives empty values for attributes that weren't previously set.
  //       Depends on replace/update policy
  // foreach($post as $k => $v){
  //   if(empty($v) || $v == ""){
  //     unset($data[$k]);
  //   }
  // }

  if(isset($data['object']['tags'])){
    $data['object']['tag'] = url_strings_to_array($data['object']['tags']);
    unset($data['object']['tags']);
  }

  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  return $json;
}

function post_to_endpoint($json, $endpoint){
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/activity+json"));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: ".$_SESSION['access_token']));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  return $response;
}

// Store config stuff
if(isset($_GET['key'])){
  $_SESSION['key'] = $_GET['key'];
}
if(isset($_GET['ep'])){
  $_SESSION['ep'] = $_GET['ep'];
}
if(isset($_GET['url'])){
  $_SESSION['url'] = $_GET['url'];
}

// Fetch feed
if(isset($_SESSION['url'])){
  $asfeed = get_feed();
}

if(isset($_POST) && !empty($_POST)){
  $result = form_to_update($_POST);
}

?>
<!doctype html>
<html>
  <head>
    <title>morph</title>
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
     h2 input { font-weight: bold; }
     form#feed { border-bottom: 1px solid silver; }
    </style>
  </head>
  <body>
    <main class="w1of2 center">
      <h1>morph</h1>
      <p>ActivityPub update client.</p>

      <?if(!isset($_SESSION['key']) || !isset($_SESSION['ep'])):?>
        <p class="fail">Don't forget to <a href="#key">'log in'</a> with your secret token and give me a pointer to your outbox endpoint.</p>
      <?endif?>
      
      <?if(isset($errors)):?>
        <div class="fail">
          <?foreach($errors as $key=>$error):?>
            <p><strong><?=$key?>: </strong><?=$error?></p>
          <?endforeach?>
        </div>
      <?endif?>
      
      <?if(isset($result)):?>
        <div>
          <p>The response from the server:</p>
          <code><?=$_SESSION['ep']?></code>
          <pre>
            <? echo $result; ?>
          </pre>
        </div>
      <?endif?>

      <form role="form" id="feed">
        <p><label for="url" class="neat">URL of album</label> <input type="url" class="neat" id="url" name="url" value="<?=isset($_SESSION['url']) ? urldecode($_SESSION['url']) : ""?>" />
        <input type="submit" value="Get" /></p>
      </form>

      <?if(isset($asfeed)):?>

        <form method="post">
          <h2><input class="neat" type="text" id="name" name="name" value="<?=isset($asfeed["name"]) ? $asfeed["name"] : "Untitled" ?>" /></h2>
          <p><input class="neat" type="datetime" name="published" id="published" value="<?=isset($asfeed["published"]) ? $asfeed["published"] : date(DATE_ATOM)?>" /> <input type="submit" value="Save" /></p>
          <input type="hidden" name="id" value="<?=$asfeed["id"]?>" />
        </form>

        <?if((is_array($asfeed["type"]) && in_array("Collection", $asfeed["type"])) || $asfeed["type"] == "Collection"):?>
          <?foreach($asfeed["items"] as $i => $item):?>
            <form class="w1of1 clearfix" method="post" id="<?=$i?>" action="#<?=$i?>">
              <div class="w1of2"><div class="inner">
                <?if(is_image($item)):?>
                  <img src="<?=$item["id"]?>" title="<?=$item["id"]?>" alt="<?=$item["id"]?>" />
                <?else:?>
                  <p><?=$item["id"]?></p>
                <?endif?>
              </div></div>
              <div class="w1of2"><div class="inner">

                <p><label class="neat" for="name<?=$i?>">Name</label> <input class="neat" type="text" name="name" id="name<?=$i?>" value="<?=isset($item["name"]) ? $item["name"] : ""?>" /></p>
                <p><label class="neat" for="published<?=$i?>">Published</label> <input class="neat" type="text" name="published" id="published<?=$i?>" value="<?=isset($item["published"]) ? $item["published"] : ""?>" /></p>
                <p><label class="neat" for="tags<?=$i?>">Tags</label> <input class="neat" type="text" name="tags" id="tags<?=$i?>" value="<?=isset($item["tag"]) ? arrayids_to_string($item["tag"]) : ""?>" /></p>
                <input type="hidden" name="id" value="<?=$item["id"]?>" />
                <p><input type="submit" value="Update" /></p>
              </div></div>
            </form>
          <?endforeach?>
        <?else:?>
          <p class="fail">I only understand Collections so far.. Stand by.</p>
        <?endif?>

      <?else:?>

        <p class="fail">Could not find a valid AS2 feed here.</p>

      <?endif?>

      <div class="color3-bg inner">
        <form role="form" id="config" class="wee">
          <p><label for="key" class="neat">Key: </label><input type="text" value="<?=isset($_SESSION['key']) ? $_SESSION['key'] : ""?>" class="neat" name="key" id="key" /> <a href="?reset=key">Reset</a></p>
          <p><label for="ep" class="neat">Outbox: </label><input type="text" value="<?=isset($_SESSION['ep']) ? urldecode($_SESSION['ep']) : ""?>" class="neat" name="ep" placeholder="Where you want update activities to be posted to" /> <a href="?reset=ep">Reset</a></p>
          <p><input type="submit" value="Save" /></p>
        </form>
      </div>
    </main>
  </body>
</html>