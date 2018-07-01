<?php
/*
 * Process.php
 * © BELDJOUHRI Abdelghani 2016 <b4n92uid@gmail.com>
 */

namespace App\Service;

use Imagine\Image\Point;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point\Center;

/**
 * Process
 * Multi layer processing class
 */
class Process
{

  function __construct($pastefile)
  {
    $content = file_get_contents($pastefile);
    $content = preg_replace("#//.+\n#", "", $content);
    $content = preg_replace("#/\*.+\*/#", "", $content);

    $this->paste = json_decode($content, true);

    if($this->paste === null)
      throw new \Exception('The Composing schema is invalide : ' . json_last_error_msg());

    $this->imagine = new \Imagine\Gd\Imagine();

    $palette = new \Imagine\Image\Palette\RGB();

    try
    {
      $baseDir = dirname($pastefile);

      foreach ($this->paste['assets'] as $name => $params)
      {
        if(isset($params['font'])) {
          $color = $palette->color($params['color']);
          $path = $baseDir . DIRECTORY_SEPARATOR . ltrim($params['font'], '/\\');

          $this->assets[$name] = $this->imagine->font($path, $params['size'], $color);
        }

        if(isset($params['image'])) {
          $path = $baseDir . DIRECTORY_SEPARATOR . ltrim($params['image'], '/\\');

          $this->assets[$name] = $this->imagine->open($path);
        }
      }

    } catch (\Exception $e) {
      throw new \Exception("LOADING ASSET: " . $e->getMessage());

    }
  }

  private function mapToDrawPoint($text, $f, $point)
  {
    $box = $f->box($text);
    $center = new Center($box);

    if($point->getX() - $box->getWidth()/2 > 0)
      return new Point($point->getX() - $box->getWidth()/2, $point->getY() - $box->getHeight()/2);

    return null;
  }

  public function resolve($exp, $dict = null)
  {
    if(preg_match('#^@([a-zA-Z_]+)#', $exp, $matches))
    {
      if(!array_key_exists($matches[1], $this->assets))
        throw new \Exception("Undefined assets `$matches[1]`");

      return $this->assets[$matches[1]];
    }

    if(preg_match_all('#\$\{([a-zA-Z_]+)(\|[a-z]+)?\}#', $exp, $matches, PREG_SET_ORDER))
    {
      foreach ($matches as $m) {
        $fullexp = $m[0];

        array_shift($m);

        $var = $m[0];
        $expanded = $var;
        $filter = isset($m[1]) ? $m[1] : null;

        if(array_key_exists($var, $dict))
          $expanded = $dict[$var];
        else
          throw new \Exception("Undefined variable `$var`");

        if($filter !== null)
        {
          $filters = array(
            'slug' => 'slugify'
          );

          $filter = substr($filter, 1);
          $expanded = $filters[$filter]($expanded);

        }

        // $exp = preg_replace('#\$\{'.$var.'(\|[a-z]+)*\}#', $expanded, $exp);
        $exp = str_replace($fullexp, $expanded, $exp);
      }
    }

    return $exp;
  }

  private function drawText($frame, $text, $fonts, $pos)
  {
    $lines = explode("\n", $text);
    $lines = array_filter($lines, function($l) {return !empty($l);});

    $selectedFont = current($fonts);
    $lineHeight = 0;

    if(count($lines) > 1)
    {
      $rect = $selectedFont->box($lines[0]);
      $boxHeight = count($lines) * $rect->getHeight();

      $lineHeight = $boxHeight / count($lines);

      $pos = new Point($pos->getX(), $pos->getY() - $lineHeight / 2);
    }

    foreach ($lines as $str) {

      $rect = $selectedFont->box($str);
      $linepos = null;

      do {
        $linepos = $this->mapToDrawPoint($str, $selectedFont, $pos);

        if($linepos !== null) {
          // var_dump($str, $selectedFont, $linepos);
          $frame->draw()->text($str, $selectedFont, $linepos);
        }
        else
          $selectedFont = next($fonts);

        if($selectedFont === false)
          break;

      } while($linepos === null);

      if($selectedFont === false)
        throw new \Exception("Cannot find correct coordinate for text : `$str`");

      $pos = new Point($pos->getX(), $pos->getY() + $lineHeight);
    }

    reset($fonts);
  }

  private function processString($frame, $layer, $u)
  {
    $t = $layer['string'];

    $t = $this->resolve($t, $u);

    $t = str_replace("\\n", "\n", $t);

    $fonts = array();

    if(is_array($layer['font'])) {
      foreach ($layer['font'] as $f) {
        $fonts[] = $this->resolve($f);
      }
    }
    else
      $fonts[] = $this->resolve($layer['font']);

    list($x, $y) = $layer['at'];

    $offset = isset($layer['offset']) ? $layer['offset'] : 0;

    $this->drawText($frame, $t, $fonts, new Point($x, $y));
  }

  private function processImage($frame, $layer, $u)
  {
    $image = $this->resolve($layer['image'], $u);

    if(is_string($image))
    {
      try {
        $image = $this->imagine->open($image);

      } catch (\Exception $e) {

        if(isset($layer['default']))
        {
          foreach($layer['default'] as $def)
          {
            $image = $this->resolve($def, $u);

            try {
              $image = $this->imagine->open($image);
              break;
            } catch (\Exception $e) {
              // continue;
            }
          }
        }
      }
    }

    if($image === null || !($image instanceof \Imagine\Image\AbstractImage))
      throw new \Exception("Unable to resolve image `$layer[image]`");

    if(isset($layer['filters']))
    {
      foreach ($layer['filters'] as $f => $params)
      {
        if($f == 'thumbnail') {
          list($fx, $fy, $ft) = $params;
          $image = $image->thumbnail(new Box($fx, $fy), $ft);
        }
      }
    }

    list($x, $y) = $layer['at'];

    try {
      $frame->paste($image, new Point($x, $y));

    } catch (\Exception $e) {
      throw new \Exception("PASTING `$layer[image]`: ".$e->getMessage());

    }
  }

  public function process(&$data, $output)
  {
      $defaults = array();

      foreach ($this->paste['defaults'] as $key => $value) {
        $defaults[$key] = $this->resolve($value, $data);
      }

      $data = array_merge($defaults, $data);

      list($width, $height) = $this->paste['frame']['size'];

      $frame = $this->imagine->create(new Box($width, $height));

      $procMap = array(
        'text' => 'processString',
        'image' => 'processImage',
      );

      foreach ($this->paste['frame']['past'] as $layer)
      {
        $procType = $layer['type'];
        $method = $procMap[$procType];
        call_user_func(array($this, $method), $frame, $layer, $data);
      }

      @mkdir(dirname($output), 0777, true);

      return $frame->save($output);
  }
}
