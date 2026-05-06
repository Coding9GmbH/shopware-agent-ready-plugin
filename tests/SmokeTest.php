<?php declare(strict_types=1);

namespace Coding9\AgentReady\Tests;

use Coding9\AgentReady\Coding9AgentReady;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Routing\Loader\AttributeClassLoader;

/**
 * Structural smoke test that verifies the plugin can be loaded by Shopware.
 *
 * It does NOT boot Shopware (no DB, no kernel) — instead it walks through the
 * artefacts a real Shopware install will load (plugin class, services.xml,
 * route attributes, snippet files, JSON, twig template) and proves they parse.
 *
 * This catches the dumb stuff: typos in services.xml, missing classes,
 * malformed snippets, route attributes that no longer compile.
 */
class SmokeTest extends TestCase
{
    public function testPluginClassExtendsShopwarePlugin(): void
    {
        self::assertTrue(is_subclass_of(Coding9AgentReady::class, Plugin::class));
    }

    public function testServicesXmlIsValidAndAllReferencedClassesExist(): void
    {
        $container = new ContainerBuilder();
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../src/Resources/config'));
        $loader->load('services.xml');

        // Every plugin-owned service must reference a class that actually exists.
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'Coding9\\AgentReady\\')) {
                continue;
            }
            self::assertTrue(
                class_exists($id) || interface_exists($id),
                "services.xml references non-existent class/interface $id"
            );
        }
    }

    public function testSnippetJsonFilesAreValidUtf8Json(): void
    {
        $files = [
            __DIR__ . '/../src/Resources/snippet/de_DE/messages.de-DE.json',
            __DIR__ . '/../src/Resources/snippet/en_GB/messages.en-GB.json',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file);
            $raw = file_get_contents($file);
            self::assertNotFalse($raw);
            self::assertTrue(mb_check_encoding($raw, 'UTF-8'), "$file is not UTF-8");
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
        }
    }

    public function testRouteAttributesParseOnControllers(): void
    {
        // Force class loading so PHP attribute reflection picks up #[Route].
        $controllers = [
            \Coding9\AgentReady\Controller\WellKnownController::class,
            \Coding9\AgentReady\Controller\RobotsTxtController::class,
        ];
        foreach ($controllers as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                // ReflectionAttribute#newInstance forces parsing; if the attribute
                // payload is malformed it throws here.
                foreach ($method->getAttributes() as $attribute) {
                    $attribute->newInstance();
                }
            }
        }
        self::assertTrue(true);
    }

    public function testTwigTemplateOverridesStorefrontBaseAtCorrectPath(): void
    {
        $twig = file_get_contents(__DIR__ . '/../src/Resources/views/storefront/base.html.twig');
        self::assertNotFalse($twig);
        self::assertStringContainsString("sw_extends '@Storefront/storefront/base.html.twig'", $twig);
        self::assertStringContainsString('block base_body_inner', $twig);
        self::assertStringContainsString("navigator.modelContext.provideContext", $twig);
    }

    public function testReleaseZipContainsMandatoryShopwareArtefacts(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive (php-zip extension) is not available');
        }

        $version = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        )['version'] ?? null;
        self::assertIsString($version, 'composer.json must declare a version');

        $zip = __DIR__ . '/../.build/Coding9AgentReady-' . $version . '.zip';
        if (!file_exists($zip)) {
            self::markTestSkipped('release zip not built yet (run `make zip` first)');
        }

        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip) === true, "could not open $zip");

        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = $archive->getNameIndex($i);
        }
        $archive->close();

        $required = [
            'Coding9AgentReady/composer.json',
            'Coding9AgentReady/src/Coding9AgentReady.php',
            'Coding9AgentReady/src/Resources/config/services.xml',
            'Coding9AgentReady/src/Resources/config/routes.xml',
            'Coding9AgentReady/src/Resources/config/config.xml',
            'Coding9AgentReady/src/Resources/config/plugin.png',
            'Coding9AgentReady/src/Resources/snippet/de_DE/SnippetFile_de_DE.php',
            'Coding9AgentReady/src/Resources/snippet/en_GB/SnippetFile_en_GB.php',
            'Coding9AgentReady/src/Resources/views/storefront/base.html.twig',
            'Coding9AgentReady/src/Controller/WellKnownController.php',
            'Coding9AgentReady/src/Subscriber/LinkHeaderSubscriber.php',
        ];
        foreach ($required as $path) {
            self::assertContains($path, $names, "release zip is missing $path");
        }

        $forbidden = [
            'Coding9AgentReady/vendor',
            'Coding9AgentReady/tests',
            'Coding9AgentReady/.phpunit.cache',
        ];
        foreach ($names as $name) {
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $name,
                    "release zip should not contain $needle (found: $name)"
                );
            }

            // Shopware Plugin Manager rejects archives that contain macOS
            // resource-fork files (__MACOSX/, ._*) for security reasons.
            self::assertStringNotContainsString(
                '__MACOSX',
                $name,
                "release zip must not contain macOS resource-fork files (found: $name)"
            );
            self::assertDoesNotMatchRegularExpression(
                '#(^|/)\._#',
                $name,
                "release zip must not contain AppleDouble files (found: $name)"
            );
        }
    }
}
