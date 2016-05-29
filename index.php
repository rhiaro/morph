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
      "@context" => array("as" => "http://www.w3.org/ns/activitystreams#", "blog" => "http://vocab.amy.so/blog#")
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

function form_to_json($post){
  $context = context();
  $data = array_merge($context, $post);
  unset($data['obtain']);
  $data["@type"] = array("blog:Acquisition");
  $data['as:published'] = $post['year']."-".$post['month']."-".$post['day']."T".$post['time'].$post['zone'];
  unset($data['year']); unset($data['month']); unset($data['day']); unset($data['time']); unset($data['zone']);
  if(isset($post['image'])) $data['as:image'] = array("@id" => $post['image'][0]);
  $json = stripslashes(json_encode($data, JSON_PRETTY_PRINT));
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

if(isset($_POST['obtain'])){
  if(isset($_SESSION['me'])){
    $endpoint = discover_endpoint($_SESSION['me']);
    $result = post_to_endpoint(form_to_json($_POST), $endpoint);
  }else{
    $errors["Not signed in"] = "You need to sign in to post.";
  }
}

?>
<!doctype html>
<html>
  <head>
    <title>morph</title>
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>
  <body>
    <main class="w1of2 center">
      <h1>morph</h1>
      <p>ActivityPub update client.</p>

      <?if(!isset($_SESSION['key']) || !isset($_SESSION['ep'])):?>
        <p class="fail">Don't forget to 'log in' with your secret token and give me a pointer to your outbox endpoint.</p>
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
          <code><?=$endpoint?></code>
          <pre>
            <? var_dump($result); ?>
          </pre>
        </div>
      <?endif?>

      <form role="form" id="feed">
        <p><label for="url" class="neat">Feed of stuff</label> <input type="url" class="neat" id="url" name="url" value="<?=isset($_SESSION['url']) ? urldecode($_SESSION['url']) : ""?>" />
        <input type="submit" value="Get" /></p>
      </form>

      <?if(isset($asfeed)):?>
        <h2><?=isset($asfeed["name"]) ? $asfeed["name"] : "Feed" ?></h2>
        <?=isset($asfeed["published"]) ? "<p>".$asfeed["published"]."</p>" : ""?>
        <?if((is_array($asfeed["type"]) && in_array("Collection", $asfeed["type"])) || $asfeed["type"] == "Collection"):?>
          <?foreach($asfeed["items"] as $i => $item):?>
            <div class="w1of1 clearfix">
              <div class="w1of2"><div class="inner">
                <?if(is_image($item)):?>
                  <img src="<?=$item["id"]?>" title="<?=$item["id"]?>" alt="<?=$item["id"]?>" />
                <?else:?>
                  <p><?=$item["id"]?></p>
                <?endif?>
              </div></div>
              <div class="w1of2"><div class="inner">
                <p><label class="neat" for="name<?=$i?>">Name</label> <input class="neat" type="text" name="name<?=$i?>" id="name<?=$i?>" value="<?=isset($item["name"]) ? $item["name"] : ""?>" /></p>
                <p><label class="neat" for="published<?=$i?>">Published</label> <input class="neat" type="text" name="published<?=$i?>" id="published<?=$i?>" value="<?=isset($item["published"]) ? $item["published"] : ""?>" /></p>
                <p><label class="neat" for="tags<?=$i?>">Tags</label> <input class="neat" type="text" name="tags<?=$i?>" id="tags<?=$i?>" value="<?=isset($item["tag"]) ? arrayids_to_string($item["tag"]) : ""?>" /></p>
              </div></div>
            </div>
          <?endforeach?>
        <?endif?>
      <?endif?>

      <!---
      <form method="post" role="form" id="obtain">
        <p><input type="submit" value="<?=isset($_GET['post']) ? "Update" : "Post"?>" class="neat" name="obtain" /></p>
        <p><label for="summary" class="neat">Description</label> <input type="text" name="as:summary" id="summary" class="neat"<?=isset($upd_descr) ? 'value="'.$upd_descr.'"' : ""?> /></p>
        <p><label for="cost" class="neat">Cost</label> <input type="text" name="blog:cost" id="cost"class="neat"<?=isset($upd_cost) ? 'value="'.$upd_cost.'"' : ""?> /></p>
        <p><label for="tags" class="neat">Tags</label> <input type="text" name="as:tag" id="tags"class="neat"<?=isset($upd_tag) ? 'value="'.$upd_tag.'"' : ""?> /></p>
        <p>
          <select name="year" id="year">
            <option value="2016"<?isset($upd_year) && ($upd_year == "2016") ? " selected" : ""?>>2016</option>
            <option value="2015"<?isset($upd_year) && ($upd_year == "2015") ? " selected" : ""?>>2015</option>
          </select>
          <select name="month" id="month">
            <?for($i=1;$i<=12;$i++):?>
              <option value="<?=date("m", strtotime("2016-$i-01"))?>"
              <?if(!isset($upd_month)):?>
                <?=(date("n") == $i) ? " selected" : ""?>
              <?else:?>
                <?=($upd_month == $i) ? " selected" : ""?>
              <?endif?>><?=date("M", strtotime("2016-$i-01"))?></option>
            <?endfor?>
          </select>
          <select name="day" id="day">
            <?for($i=1;$i<=31;$i++):?>
              <option value="<?=date("d", strtotime("2016-01-$i"))?>"
              <?if(!isset($upd_day)):?>
                <?=(date("j") == $i) ? " selected" : ""?>
              <?else:?>
                <?=($upd_day == $i) ? " selected" : ""?>
              <?endif?>><?=date("d", strtotime("2016-01-$i"))?></option>
            <?endfor?>
          </select>
          <input type="text" name="time" id="time" value="<?=isset($upd_time) ? $upd_time : date("H:i:s")?>" />
          <input type="text" name="zone" id="zone" value="<?=isset($upd_tz) ? $upd_tz : date("P")?>" />
        </p>
        <ul class="clearfix">
          <?foreach($images as $image):?>
            <li class="w1of5"><p><input type="radio" name="image[]" id="image" value="<?=$image?>" <?=isset($upd_image) && $upd_image == $image ? " checked" : ""?> /> <label for="image"><img title="<?=$image?>" src="https://images1-focus-opensocial.googleusercontent.com/gadgets/proxy?url=<?=$image?>&container=focus&resize_w=200&refresh=2592000" width="100px" /></label></p></li>
          <?endforeach?>
        </ul>
      </form>
      -->
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