Copied unchanged from https://github.com/WordPress/wordpress.org at commit
`79cf97f8d715a4befff1fc6a464f5940116aa190`, path
`wordpress.org/public_html/wp-content/plugins/plugin-directory/` (GPL-2.0-or-later):

- `readme/class-parser.php`: the directory's readme parser.
- `class-markdown.php`, `libs/michelf-php-markdown-1.6.0/`: the Markdown the parser uses.

`release/php/readme.php` loads them inside WordPress so the readme strings
match exactly what translate.wordpress.org imports. Update them by copying the
files again from a newer commit.
