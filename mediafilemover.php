#!/usr/bin/php
<?php
$TVDIR = '/home/jason/Videos/TV';
$MVDIR = '/home/jason/Videos/Movies';
$UNRAR = trim(`which unrar`); // May have to hardcode if php doesn't have PATH information
$MVBIN = '/bin/mv';
$LSBIN = '/bin/ls';
$RMBIN = '/bin/rm';

$autoDelete = 0;
$verbose = 0;
$tvf = explode("\n",shell_exec('ls $HOME/Videos/TV'));

function folder($str) {
  global $tvf;
  $f =  trim(ucwords(preg_replace('/[\.\- ]/',' ',$str)));
  $fl = strtolower($f);
  foreach($tvf as $tv)
    if($fl == strtolower($tv))
      return $tv;
  return $f;
}
function filename($str,$ep) {
  global $tvf;
  if(preg_match('/s([0-9]{2})e([0-9]{2})$/i',$ep,$m))
    $ep = "S{$m[1]}E{$m[2]}";
  else if(preg_match('/^([0-9]+)x([0-9]+)$/i',$ep,$m))
    $ep = sprintf("S%02dE%02d",$m[1],$m[2]);

  $n = str_replace(' ','.',trim(ucwords(preg_replace('/[\.\- ]/',' ',$str))));
  $nl = strtolower($n);
  foreach($tvf as $tv) {
     $tv = str_replace(' ','.',$tv);
      if($nl == strtolower($tv))
        return "$tv.$ep";
  }
  return "$n.$ep";
  
}
function movie($str) {
  $f =  trim(ucwords(preg_replace('/[\.\- ]/',' ',$str)));
  return $f;
}

function moveto($from,$to) {
  global $MVBIN,$moveCount;
    $moveCount[dirname($from)]++;
    if(!is_dir(dirname($to)))
      shell_exec("/bin/mkdir -p ".escapeshellarg(dirname($to)));
 
    $from = escapeshellarg($from);
    if(is_file($to))
      $to = preg_replace('/(\.avi|\.mkv)/','(2)$1',$to);
    $to = escapeshellarg($to);
    shell_exec("$MVBIN $from $to");
    echo "Moved to: $to\n";
}
$markForDelete = array();

function extractRar($rar) {

  global $UNRAR,$verbose;

  $descriptorspec = array(
    0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
    1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
    2 => array("pipe", "w")   // stderr is a file to write to
  );
  $file = escapeshellarg($rar);
  if($verbose)
    echo "$UNRAR e -y $file\n";
  echo "Extracting ".$rar."\n";
  $proc = proc_open("$UNRAR e -y $file",$descriptorspec,$pipes);
  if (is_resource($proc)) {
    $counter = 0;
    $files = array();
    $rarParts = array();
    $percent = false;
    while(1) {
      $counter++;
      $read = array($pipes[1]);
      $write = NULL;
      $except = NULL;
      $b = stream_select($read,$write,$except,1);
      $c='';
      $e='';
      if($b>0) {
        $c = explode("\n",trim(fread($read[0],1024)));
        foreach($c as $l) {
//          echo $l."\n";
          if(strpos(trim($l),'Extracting from')===0) {
            $rarParts[] = substr($l,16);
          } else if(strpos(trim($l),'Extracting ')===0) {
            $file = trim(substr($l,10));
            if(!in_array($file,$files)) {
              $files[] = $file;
            }
          } else if(strpos(trim($l),'...')===0) {
            $file = trim(substr($l,3));
            if(!in_array($file,$files)) {
              $files[] = $file;
            }
          } else if(preg_match('/([0-9]+)%/',$l,$m)) {
            if($percent)
             echo "\010\010\010";
            echo "{$m[1]}%";
            $percent=true;
          } else if(trim($l)=='All OK') {
            ///if($percent)
            // echo "\010\010\010";
            //echo "All Done";
            //$percent=true;
          }
       }
      }
      $s = proc_get_status($proc);
      if(!$s['running'])
        break;
    }
    echo "\n";
    if($verbose) {
      echo "Extracted: ";
      echo implode(", ",$files)."\n";
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $r = proc_close($proc);
    if($r !== -1) {
      'Unrar Exited with unexcepted code: '.$r."\n";
      return false;
    }
    return $files;
  }
}

function processDir($dir) {
  global $LSBIN;
  $dirarg = escapeshellarg($dir);
  $dl = explode("\n",trim(shell_exec("$LSBIN $dirarg")));
  foreach($dl as $f) {
    $f = trim($f);
    if($f)
      process("$dir/$f");
  }
}
/**
 * file = full path
 */
$moveCount = array();
function process($file) {
  global $TVDIR,$MVDIR,$RMBIN,$autoDelete,$verbose,$moveCount;
  if(strpos($file,'/')!==0)
    $value = realpath(getcwd().'/'.$file);
  else 
    $value = realpath($file);
  if(!$value)
    return;
  if($verbose)
    echo "Processing: $value\n";
  chdir(dirname($value));
  $extensions = 'avi|mkv|mp4';
  if(is_dir($value)) {
    $moveCount[$value] = 0;
    processDir($value);
    if($autoDelete && $moveCount[$value]) {
      $f = escapeshellarg($value);
      `$RMBIN -rf $f`;
      echo "Deleted: $value\n";
    }
    return;
  }
  /** Extract only the part01.rar or .rar file in dir
   *  eg 
   * mr.sunshine.101.hdtv-lol.r00
   * mr.sunshine.101.hdtv-lol.r01
   * mr.sunshine.101.hdtv-lol.r02
   * mr.sunshine.101.hdtv-lol.r03
   * mr.sunshine.101.hdtv-lol.rar <--
   * 
   *  -- OR -- 
   * mr.sunshine.101.hdtv-lol.part01.rar <--
   * mr.sunshine.101.hdtv-lol.part02.rar
   * mr.sunshine.101.hdtv-lol.part03.rar
   * mr.sunshine.101.hdtv-lol.part04.rar
   * mr.sunshine.101.hdtv-lol.part05.rar
   * 
   **/
  else if(preg_match('/.*\.part[0]+1\.rar/i',$value,$m) 
    || (!preg_match('/.*\.part[0-9]+\.rar/i',$value) && preg_match('/.*\.rar/i',$value,$m))) {
    if($verbose)
      echo "Extract: $value\n";
    $files = extractRar($value);
    if($verbose) {
      echo "\n";
      print_r($files);
      echo "\n";
    }
    foreach($files as $f) {
      if($verbose)
        echo "Process Extracted: $f\n";
      process("$f");
    }
  }
  // Ignore samples
  else if(stripos($value,'sample')!==false) {
    
  // TV in format: name.S01E01.tag.avi
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\. -](s[0-9]{2}e[0-9]{2})[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$TVDIR.'/'.folder($m[2]).'/'.filename($m[2],$m[3]).'.'.$m[4]);
  
  // TV in format: name 1x14 episode title.avi  
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\. -]([0-9]{1,2}x[0-9]{2})[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$TVDIR.'/'.folder($m[2]).'/'.filename($m[2],$m[3]).'.'.$m[4]);
  
  // TV in format: name.2010.10.06.tag.avi
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\. -](20[0-9]{2}[\.\-][0-9]{2}[\.\-][0-9]{2})[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$TVDIR.'/'.folder($m[2]).'/'.filename($m[2],$m[3]).'.'.$m[4]);

  // TV with path in form /name.S01E01.tag/name...avi
  } else if(preg_match('#/([a-z0-9\- \._]+)[\. \-](s[0-9]{2}e[0-9]{2})[^/]*/[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$TVDIR.'/'.folder($m[1]).'/'.filename($m[1],$m[2]).'.'.$m[3]);

  // Movie parent in format: Movie.2010.dvd/CD1/tag-snom.avi 
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\ .-\[\(]((19|20)[0-9]{2})[^/]*/CD(1|2)/[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$MVDIR.'/'.movie($m[2]).".{$m[3]}.part{$m[5]}.{$m[6]}");
  
  // Movie in format: movie.2010.dvd.avi (1900-2099) I don't have old movies 
  } else if(preg_match("#(/)([a-z0-9\- \._]+)[\ .-\[\(]((19|20)[0-9]{2})[^/]*\.($extensions)\$#i",$value,$m)) {
    moveto($value,$MVDIR.'/'.movie($m[2]).".{$m[3]}.{$m[5]}");
  
  // Movie parent in format: Movie.2010.dvd/tag-snom.avi 
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\ .-\[\(]((19|20)[0-9]{2})[^/]*/[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$MVDIR.'/'.movie($m[2]).".{$m[3]}.{$m[5]}");
  
  // Movie in format: movie.dvdrip.tag.avi (1900-2099) I don't have old movies 
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\ .-\[\(](dvdrip|bdrip)[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$MVDIR.'/'.movie($m[2]).".{$m[4]}");
  
  // Movie parent in format: Movie.dvdrip.tag/tag-snom.avi 
  } else if(preg_match('#(/)([a-z0-9\- \._]+)[\ .-\[\(](dvdrip|bdrip)[^/]*/[^/]*\.(avi|mkv)$#i',$value,$m)) {
    moveto($value,$MVDIR.'/'.movie($m[2]).".{$m[4]}");

  }
}
chdir($_SERVER['PWD']);
$dir = getcwd();
foreach($argv as $i=>$value) {
  if($i==0)
    continue;
  if($value == '-d')
    $autoDelete = 1;
  else if($value == '-v')
    $verbose = 1;
  else if($value == '-c')
   $makeCopy = 1;
  else {
    process("$dir/$value");
  } 
}
