Image Composer
==============

This tool help generate multiple images based on a schema defined as json file and a database (csv, xlsx, json...)

```json
// schema.json
{
  "assets": {
    "background": {"image": "assets/template.png"},
    "primary": {"font": "fonts/eurostile-extended.ttf", "size": 38, "color": "000000"}
  },

  "defaults": {
  },

  "frame": {
    "size": [639, 1004],

    "past": [
      {"type": "image", "at": [0, 0], "image":"@background"},
      {"type": "text", "at": [319, 950], "string": "PARTICIPANT ${number}", "font": "@primary"}
    ]
  }
}
```

```
# database.csv
number
001
002
003
004
005
006
007
008
009
010
011
012
013
014
015
016
017
018
019
020
...
```

```sh
php image-composer/cli.php compose schema.json database.csv badges-${number}.png
```
