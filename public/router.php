<?php
// Router exclusively for the PHP development server. Never expose workspace files.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('#^/(css|js|images|vendor/ace)/[a-zA-Z0-9_.\/-]+$#D',$path) && !str_contains($path,'..') && is_file(__DIR__.$path)) return false;
require __DIR__.'/index.php';
