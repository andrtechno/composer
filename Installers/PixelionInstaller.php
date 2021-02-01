<?php

namespace Pixelion\Composer\Installers;

class PixelionInstaller extends BaseInstaller
{
    protected $locations = array(
        'module-custom' => 'modules/{$name}/',
        'theme' => 'web/themes/{$name}/',
        'widget-custom' => 'widgets/{$name}/',
    );
}
