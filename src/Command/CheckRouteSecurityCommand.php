<?php

declare(strict_types=1);

namespace Xact\CheckRouteSecurity\Command;

use Exception;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

class CheckRouteSecurityCommand extends Command
{
    protected const EXCLUDE_KNOWN_PREFIXES = [
        'web_profiler',
        'twig',
    ];
    protected static string $commandName = 'xact:check-route-security';
    protected string $projectDir;
    /** @var string[] */
    protected array $excludeRoutes;
    protected RouterInterface $router;
    protected AccessDecisionManagerInterface $accessDecisionManager;
    protected string $secret;

    /**
     * @param string[] $excludeRoutes
     */
    public function __construct(string $projectDir, array $excludeRoutes, RouterInterface $router, AccessDecisionManagerInterface $accessDecisionManager)
    {
        parent::__construct(self::$commandName);

        $this->projectDir = $projectDir;
        $this->excludeRoutes = $excludeRoutes;
        $this->router = $router;
        $this->accessDecisionManager = $accessDecisionManager;
        $this->secret = md5((string)rand());
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('This command iterates controller methods and checks for the existence of security controls.')
        ;
    }

    /**
     * {@inheritdoc}
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkRouteSecurity($output);

        return 0;
    }

    protected function checkRouteSecurity(OutputInterface $output): void
    {
        $output->writeln('<info>Creating list of routes ...</info>');

        $noSecurityCounter = 0;
        $checkedCounter = 0;
        $controllers = [];

        // Get all valid routes
        $routes = $this->router->getRouteCollection();
        foreach ($routes as $route) {
            $defaults = $route->getDefaults();
            if (isset($defaults['_controller'])) {
                $controller = $defaults['_controller'];

                if (in_array($controller, $controllers, true) || strpos($controller, '::') === false || $this->isRouteExcluded($controller)) {
                    continue;
                }

                // If the route is accessible anonymously via access_control, we need to check the controller code.
                $anonymousToken = new AnonymousToken($this->secret, 'anon.', []);
                $request = Request::create($route->getPath());
                $attributes = [AuthenticatedVoter::IS_AUTHENTICATED_ANONYMOUSLY];
                if (strpos($route->getPath(), 'login') !== false) {
                    $x = 1;
                }
                if ($this->accessDecisionManager->decide($anonymousToken, $attributes, $request, true)) {
                    $controllers[] = $controller;
                }
                $checkedCounter++;
            }
        }

        // Check if all valid routes have a security check
        foreach ($controllers as $controller) {
            $parts = explode('::', $controller);
            if (count($parts) !== 2) {
                $output->writeln("<error>'{$controller}' is not a valid route with function format.</error>");
            }

            $route = $parts[0];
            $function = $parts[1];

            try {
                $reflectionClass = new ReflectionClass($route);
                $file_name = $reflectionClass->getFileName();

                if (!file_exists($file_name)) {
                    $output->writeln("<error>The file '{$file_name}' does not exist. ({$controller}).</error>");
                    continue;
                }

                $fileContent = file_get_contents($file_name);
            } catch (Exception $e) {
                $output->writeln("<comment>Could not open the file for class {$route}.</comment>");
                continue;
            }

            // start of regular expression
            $regex = '(';
            // find "public function myFunction"
            $regex .= '(public function ' . $function . ')';
            // then everything until "{"
            $regex .= '(\([^{]*\{)';
            // then everything on next line
            $regex .= '(\s.*)';
            // until "$this->denyAccessUnlessGranted" ou "!$this->isGranted"
            $regex .= '(\$this->denyAccessUnlessGranted|!\$this->isGranted)';
            // end of regular expression
            $regex .= ')';

            if (!preg_match($regex, $fileContent)) {
                $output->writeln("<error>No security checks were found in controller method '{$controller}'</error>");
                $noSecurityCounter++;
            }
        }

        $checkedStyle = $noSecurityCounter === 0 ? 'info' : 'error';
        $output->writeln('');
        $output->writeln("<info>{$checkedCounter} functions have been checked.</info>");
        $output->writeln("<{$checkedStyle}>{$noSecurityCounter} functions without security checks have been found.</{$checkedStyle}>");
    }

    protected function isRouteExcluded(string $route): bool
    {
        foreach (self::EXCLUDE_KNOWN_PREFIXES as $exclude) {
            if (strncmp($route, $exclude, strlen($exclude)) === 0 || in_array($route, $this->excludeRoutes, true)) {
                return true;
            }
        }
        return false;
    }
}
