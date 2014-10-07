<?php
/**
 * Created by PhpStorm.
 * User: parsingphase
 * Date: 10/09/14
 * Time: 14:23
 */

namespace Phase\Blog\Silex;


use Phase\Adze\Application;
use Phase\Blog\Blog;
use Phase\Blog\BlogPost;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Silex Controller for blog-related pages
 * @package Phase\Blog\Silex
 */
class BlogController
{
    /**
     * @var Blog
     */
    protected $blog;

    /**
     * @var Application
     */
    protected $app;

    public function __construct(Blog $blog, Application $app)
    {
        $this->blog = $blog;
        $this->app = $app;
    }

    public function indexAction()
    {
        $pastOnly = $publicOnly = !$this->app->getSecurityContext()->isGranted('ROLE_ADMIN');
        $posts = $this->blog->fetchRecentPosts(5, $publicOnly, $pastOnly);
        return $this->app->render('@blog/index.html.twig', ['posts' => $posts]);
    }

    public function singlePostAction($uid, $slug)
    {
        $post = $this->blog->fetchPostById($uid);

        if (!(
            (($post->getSecurity() == BlogPost::SECURITY_PUBLIC) && $post->getTime() < new \DateTime())
            || $this->app->getSecurityContext()->isGranted('ROLE_ADMIN'))
        ) {
            throw new AccessDeniedHttpException; // FIXME just 404?
        }

        if ($slug !== $post->getSlug()) {
            return $this->app->redirect(
                $this->app->path(
                    'blog.post',
                    ['uid' => $uid, 'slug' => $post->getSlug()]
                )
            );
        }
        return $this->app->render('@blog/post.html.twig', ['post' => $post]);
    }

    public function archiveAction()
    {
        $pastOnly = $publicOnly = !$this->app->getSecurityContext()->isGranted('ROLE_ADMIN');

        $posts = $this->blog->fetchAllPostsNoBody($publicOnly, $pastOnly);
        return $this->app->render('@blog/archive.html.twig', ['posts' => $posts]);
    }

    public function newPostAction(Request $request)
    {
        // There may be neater ways of doing this?
        if (!$this->app->getSecurityContext()->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException;
        }

        //Forms ref: http://symfony.com/doc/2.5/book/forms.html
        $action = $this->app->url('blog.newPost');

        $blogPost = new BlogPost();
        $blogPost->setTime(new \DateTime());
        $creator = $this->app->getCurrentUser();
        $blogPost->setCreator($creator);

        $form = $this->createBlogPostForm($blogPost, $action);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->blog->savePost($blogPost);

            $newBlogId = $blogPost->getId();

            // redirect somewhere
            return $this->app->redirect(
                $this->app->path(
                    'blog.post',
                    ['uid' => $newBlogId, 'slug' => $blogPost->getSlug()]
                )
            );
        }

        return $this->app->render('@blog/editPost.html.twig', ['blogPostForm' => $form->createView()]);
    }

    public function editPostAction(Request $request, $uid)
    {
        // There may be neater ways of doing this?
        if (!$this->app->getSecurityContext()->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException;
        }

        $blogPost = $this->blog->fetchPostById($uid);

        //Forms ref: http://symfony.com/doc/2.5/book/forms.html
        $action = $this->app->url('blog.editPost', ['uid' => $blogPost->getId(), 'slug' => $blogPost->getSlug()]);
        $form = $this->createBlogPostForm($blogPost, $action);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $this->blog->savePost($blogPost);

            $newBlogId = $blogPost->getId();

            // redirect somewhere
            return $this->app->redirect(
                $this->app->path(
                    'blog.post',
                    ['uid' => $newBlogId, 'slug' => $blogPost->getSlug()]
                )
            );
        }

        return $this->app->render('@blog/editPost.html.twig', ['blogPostForm' => $form->createView()]);
    }

    /**
     * @param BlogPost $blogPost
     * @param $action
     * @return Form
     */
    protected function createBlogPostForm(BlogPost $blogPost, $action)
    {
        //NB: data can be array or target object
        $formBuilder = $this->app->getFormFactory()->createBuilder('form', $blogPost)
            ->add('subject')
            ->add('body', 'textarea')
            ->add('time', 'datetime')
            ->add('save', 'submit')
            ->add(
                'security',
                'choice',
                ['choices' => [BlogPost::SECURITY_PUBLIC => 'Public', BlogPost::SECURITY_PRIVATE => 'Private']]
            )
            ->setAction($action);
        /* @var FormBuilder $formBuilder Interface definition means PhpStorm chokes there */

        if ($blogPost->getId()) {
            $formBuilder->add('id', 'hidden');
        }

        $form = $formBuilder->getForm();
        return $form;
    }

    /**
     * Currently allow admin to delete any post
     * @param Request $request
     * @param int $uid
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deletePostAction(Request $request, $uid)
    {
        // There may be neater ways of doing this?
        if (!$this->app->getSecurityContext()->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException;
        }
        $blogPost = $this->blog->fetchPostById($uid);
        $action = $this->app->url('blog.deletePost', ['uid' => $blogPost->getId(), 'slug' => $blogPost->getSlug()]);
        $formData = ['uid' => $uid, 'sure' => false];
        $formBuilder = $this->app->getFormFactory()
            ->createBuilder('form', $formData)
            ->add('uid', 'hidden')
            ->add('sure', 'checkbox', ['label' => 'Yes, delete this post'])
            ->add('delete', 'submit')
            ->setAction($action);

        $viewData = [];

        /* @var FormBuilder $formBuilder Interface definition means PhpStorm chokes there */
        $form = $formBuilder->getForm();

        $form->handleRequest($request);

        $deleted = false;

        if ($form->isValid()) {
            $data = $form->getData();
            $sure = $data['sure'];
            if ($sure && ($uid === $data['uid'])) {
                $deleted = $this->blog->deletePostById($uid);
            }
        }

        if ($deleted) {
            $viewData['deletedPost'] = $blogPost;
        } else {
            $viewData = ['blogPost' => $blogPost, 'form' => $form->createView()];
        }

        return $this->app->render('@blog/deletePost.html.twig', $viewData);
    }
}
