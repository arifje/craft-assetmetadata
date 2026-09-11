<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\support;

use carlcs\assetmetadata\services\Metadata;
use carlcs\assetmetadata\Settings;
use carlcs\assetmetadata\web\twig\Extension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The metadata service with the two seams the unit tests need: settings that don’t come from an
 * installed plugin, and subfield templates rendered by a plain Twig environment (Craft’s view
 * component needs an installed site). Everything else is the real service.
 */
final class TestMetadataService extends Metadata
{
    public Settings $testSettings;

    private ?Environment $_twig = null;

    public function __construct(array $config = [])
    {
        $this->testSettings = new Settings();
        parent::__construct($config);
    }

    protected function settings(): Settings
    {
        return $this->testSettings;
    }

    protected function renderTemplate(string $template, array $variables): string
    {
        if ($this->_twig === null) {
            $this->_twig = new Environment(new ArrayLoader(), ['autoescape' => false, 'strict_variables' => false]);
            $this->_twig->addExtension(new Extension());
        }

        return $this->_twig->createTemplate($template)->render($variables);
    }
}
