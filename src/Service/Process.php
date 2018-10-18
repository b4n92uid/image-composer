<?php
/*
 * File: Process.php
 * Project: Service
 * File Created: Sunday, 1st July 2018 14:38:00
 * Author: BELDJOUHRI Abdelghani (b4n92uid@gmail.com)
 * -----
 * Last Modified: Thursday, 18th October 2018 20:50:56
 * Modified By: BELDJOUHRI Abdelghani (b4n92uid@gmail.com>)
 * -----
 * Copyright 2018, StarFeel Interactive
 */


namespace App\Service;

use Imagine\Image\Point;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point\Center;

/**
 * The composition class
 *
 * This class is responsible for generating the final image file
 * from the schema file and the given data
 */
class Process
{

  /**
   * Default constructor
   *
   * @param $schemaFile the json file that describe the composition process
   */
  function __construct($schemaFile)
  {
    // Load and parse compose file content

    $content = file_get_contents($schemaFile);
    $content = preg_replace("#//.+\n#", "", $content);
    $content = preg_replace("#/\*.+\*/#", "", $content);

    $this->paste = json_decode($content, true);

    if($this->paste === null)
      throw new \Exception('[Schema] The Composing schema is invalide : ' . json_last_error_msg());

    // Initialize Imagine library

    $this->imagine = new \Imagine\Gd\Imagine();

    $palette = new \Imagine\Image\Palette\RGB();

    // Load defined assets

    $baseDir = dirname($schemaFile);

    $this->loadAssets($baseDir);
  }

  /**
   * Load asset defined in the "assets" section
   * relative to $baseDir
   */
  private function loadAssets($baseDir)
  {
    try
    {
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
      throw new \Exception('[Asset] ' . $e->getMessage());

    }
  }

  /**
   * Get the draw point computed from desired center point and the text bounding box
   *
   * @return null if the draw point exceed layer size
   */
  private function mapToDrawPoint($text, $f, $point)
  {
    $box = $f->box($text);
    $center = new Center($box);

    if($point->getX() - $box->getWidth()/2 > 0)
      return new Point($point->getX() - $box->getWidth()/2, $point->getY() - $box->getHeight()/2);

    return null;
  }

  /**
   * Resolve the expression string
   *
   * Return the assets identified by @id
   *
   * Replace placeholder ${var} with variables value
   */
  public function resolve($exp, $dict = null)
  {
    if(preg_match('#^@([a-zA-Z_]+)#', $exp, $matches))
    {
      if(!array_key_exists($matches[1], $this->assets))
        throw new \Exception("[Resolve] Undefined assets `$matches[1]`");

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
          throw new \Exception("[Resolve] Undefined variable `$var`");

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

  /**
   * Draw the text in the given $frame with selected $fonts and $pos
   *
   * @param $pos define the center of the texte
   *
   * @param $text can be a multiline string
   *
   * @param $fonts must be an array of font resource to try in order in case that
   * the current font is to big for the rendered text
   */
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
        throw new \Exception("[String] Cannot find valid coordinate for text : `$str`");

      $pos = new Point($pos->getX(), $pos->getY() + $lineHeight);
    }

    reset($fonts);
  }

  /**
   * Process a string layer
   *
   * @param $frame the finale frame
   *
   * @param $layer the layer options
   *
   * @param $data the associative array where to resolve data
   */
  private function processString($frame, $layer, $data)
  {
    $t = $layer['string'];

    $t = $this->resolve($t, $data);

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

  /**
   * Process an image layer
   *
   * @param $frame the finale frame
   *
   * @param $layer the layer options
   *
   * @param $data the associative array where to resolve data
   */
  private function processImage($frame, $layer, $data)
  {
    $image = $this->resolve($layer['image'], $data);

    if(is_string($image))
    {
      try {
        $image = $this->imagine->open($image);

      } catch (\Exception $e) {

        if(isset($layer['default']))
        {
          foreach($layer['default'] as $def)
          {
            $image = $this->resolve($def, $data);

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
      throw new \Exception("[Image] Unable to resolve image `$layer[image]`");

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
      throw new \Exception("[Image] `$layer[image]`: ".$e->getMessage());

    }
  }

  /**
   * Generate the composition with the given $data
   *
   * @param array $data associative array where to fetch resolved values
   *
   * @param $output the output file path of the composition
   */
  public function process($data, $output)
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
