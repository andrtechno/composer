<?php

namespace Pixelion\Composer\Installers;

class PixelionInstaller extends BaseInstaller
{
    protected $locations = array(
        'module' => 'vendor/{$vendor}/{$name}/',
        'widget' => 'vendor/{$vendor}/{$name}/',
        'theme' => 'web/themes/{$name}/',
        'module-custom' => 'modules/{$name}/',
        'widget-custom' => 'widgets/{$name}/',
    );
}
