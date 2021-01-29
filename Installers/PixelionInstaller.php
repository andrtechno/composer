<?php
namespace Pixelion\Composer\Installers;

class PixelionInstaller extends BaseInstaller
{
    protected $locations = array(
        'module'           => 'modules/{$name}/',
        'theme'            => 'web/themes/{$name}/',
        'widgets'          => 'widgets/{$name}/',
    );
}
