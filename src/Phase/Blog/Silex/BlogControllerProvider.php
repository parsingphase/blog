<?php
/**
 * Created by PhpStorm.
 * User: parsingphase
 * Date: 06/09/14
 * Time: 21:16
 */

namespace Phase\Blog\Silex;


use Phase\Adze\Application as AdzeApplication;
use Phase\Blog\Blog;
use Silex\Application as SilexApplication;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;

/**
 * Silex routing and controller setup for blog-related pages
 *
 * @see Silex book chapter 6, ControllerProviders
 * @TODO rename to ....\Silex\BlogControllerProvider?
 * @package Phase\Blog
 */
class BlogControllerProvider implements ControllerProviderInterface
{

    /**
     * Returns routes to connect to the given application.
     *
     * @param SilexApplication $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     */
    public function connect(SilexApplication $app)
    {
        // Make sure we're using an Adze app, and use that knowledge to set up the required template and resource paths
        // It's possible we should really do this as a ServiceProvider ... ?

        $app = AdzeApplication::assertAdzeApplication($app);
        $controllers = $app->getControllerFactory();

        $moduleBaseDir = dirname(dirname(dirname(dirname(__DIR__))));

        //Identify exact path
        $app->getTwigFilesystemLoader()->addPath(
            $moduleBaseDir . '/templates/blog/default',
            'blog'
        );

        //Identify parent path so that we can refer directly to @blog/default if required
        $app->getTwigFilesystemLoader()->addPath(
            $moduleBaseDir . '/templates/blog',
            'blog'
        );

        $app->getResourceController()->addPathMapping('parsingphase/blog', $moduleBaseDir . '/resources');

        $app['blog.controller'] = $app->share(
            function (AdzeApplication $app) {
                $dbConnection = $app->getDatabaseConnection();
                $blog = new Blog($dbConnection, $app);
                $blogController = new BlogController($blog, $app);
                return $blogController;
            }
        );

        // Now set up routes for the blog subsystem

        /*
         * We have a slight gotcha for IDE code analysis here; Adze defines the Route class that we get dynamically,
         * and Phase\Adze\Route implements the \Silex\Route\SecurityTrait - but the object we call is a
         * \Silex\ControllerCollection that uses __call to pass through to the route, leaving IDEs baffled and unable
         * to recognise that ControllerCollection can handle ->secure()
         *
         * For PHPStorm, see http://stackoverflow.com/a/12583095/113076
         *
         * (Settings -> Inspections -> PHP -> Undefined -> Undefined Method -> Downgrade severity if __magic methods are present)
         *
         * TODO Move this explanation to a blogpost on phase.org and link to that as required
         */

        $controllers->match(
            '/newPost',
            'blog.controller:newPostAction'
        )->bind('blog.newPost')->method('POST|GET');

        $controllers->get(
            '/archive',
            'blog.controller:archiveAction'
        )->bind('blog.archive');

        $controllers->match(
            '/{uid}_{slug}/edit',
            'blog.controller:editPostAction'
        )->bind('blog.editPost')->assert('uid', '\d+')->method('POST|GET')->secure('ROLE_ADMIN');

        $controllers->get(
            '/{uid}_{slug}',
            'blog.controller:singlePostAction'
        )->bind('blog.post')->assert('uid', '\d+');

        $controllers->get(
            '/',
            'blog.controller:indexAction'
        )->bind('blog.index');

        return $controllers;

    }
}
