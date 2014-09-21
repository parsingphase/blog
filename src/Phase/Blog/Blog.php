<?php
/**
 * Created by PhpStorm.
 * User: parsingphase
 * Date: 09/09/14
 * Time: 14:10
 */

namespace Phase\Blog;


use Doctrine\DBAL\Connection;
use Phase\Adze\Application;
use Symfony\Component\Routing\Exception\InvalidParameterException;

/**
 * Access class for posts on a blog
 * @package Phase\Blog
 */
class Blog
{

    /**
     * @var Connection
     */
    protected $dbConnection;

    /**
     * @var Application
     */
    protected $app;

    /**
     * Set up access class using given DB connection & app resources
     * @param Connection $dbConnection
     * @param Application $app
     */
    public function __construct(Connection $dbConnection, Application $app)
    {
        $this->dbConnection = $dbConnection;
        $this->app = $app;
    }

    /**
     * Store a blog post to the configured DBAL
     *
     * @param BlogPost $blogPost
     * @return bool
     */
    public function savePost(BlogPost $blogPost)
    {
        $return = false;
        $creator = $blogPost->getCreator();

        if (!$creator) {
            throw new InvalidParameterException("Invalid user");
        }

        if ($blogPost->getId()) {
            $updateCount = $this->dbConnection->update(
                'blog_post',
                [
                    'time' => $blogPost->getTime()->format('Y-m-d H:i:s'),
                    'subject' => $blogPost->getSubject(),
                    'body' => $blogPost->getBody(),
                    'security' => $blogPost->getSecurity(),
                    'creatorId' => $creator->getId()
                ],
                ['id' => $blogPost->getId()]
            );

            $return = (bool)$updateCount;
        } else {
            if (!$blogPost->getTime()) {
                $blogPost->setTime(new \DateTime());
            }

            $updateCount = $this->dbConnection->insert(
                'blog_post',
                [
                    'time' => $blogPost->getTime()->format('Y-m-d H:i:s'),
                    'subject' => $blogPost->getSubject(),
                    'body' => $blogPost->getBody(),
                    'security' => $blogPost->getSecurity(),
                    'creatorId' => $creator->getId()
                ]
            );

            if ($updateCount) {
                $id = $this->dbConnection->lastInsertId();
                if ($id) {
                    $blogPost->setId($id);
                    $return = true;
                }
            }
        }

        return $return;
    }

    /**
     * Load a blog post from the configured DBAL by primary key
     *
     * @param $presentPostId
     * @return null|BlogPost
     */
    public function fetchPostById($presentPostId)
    {
        $post = null;
        $sql = 'SELECT * FROM blog_post WHERE id=?';
        $row = $this->dbConnection->fetchAssoc($sql, [$presentPostId]);
        if ($row) {
            $post = $this->createPostFromDbRow($row);
        }
        return $post;
    }

    /**
     * Fetch $count of the most recent posts ordered newest-first, taking future time & security into account
     *
     * @param int $count
     * @param bool $publicOnly
     * @param bool $pastOnly
     * @throws \InvalidArgumentException
     * @return BlogPost[]
     */
    public function fetchRecentPosts($count = 5, $publicOnly = false, $pastOnly = false)
    {
        $posts = [];
        if (!is_int($count)) {
            throw new \InvalidArgumentException();
        }

        $whereParts = [];
        $queryParams = [];

        if ($publicOnly) {
            $whereParts[] = " security= :security ";
            $queryParams['security'] = BlogPost::SECURITY_PUBLIC;
        }

        if ($pastOnly) {
            $now = new \DateTime();
            $whereParts[] = " `time` <= :when "; // no "NOW()" in sqlite?
            $queryParams['when'] = $now->format('Y-m-d H:i');
        }

        if ($whereParts) {
            $whereClause = ' WHERE ' . join(' AND ', $whereParts);
        } else {
            $whereClause = '';
        }

        $sql = "SELECT * FROM blog_post $whereClause ORDER BY `time` DESC LIMIT $count";
        $rows = $this->dbConnection->fetchAll($sql, $queryParams);
        foreach ($rows as $row) {
            $posts[] = $this->createPostFromDbRow($row);
        }
        return $posts;
    }


    /**
     * Fetch all posts ordered newest-first, but don't load their bodies; use for archive lists etc
     *
     * @param bool $publicOnly
     * @param bool $pastOnly
     * @return BlogPost[]
     */
    public function fetchAllPostsNoBody($publicOnly = false, $pastOnly = false)
    {

        $whereParts = [];
        $queryParams = [];

        if ($publicOnly) {
            $whereParts[] = " security= :security ";
            $queryParams['security'] = BlogPost::SECURITY_PUBLIC;
        }

        if ($pastOnly) {
            $now = new \DateTime();
            $whereParts[] = " `time` <= :when "; // no "NOW()" in sqlite?
            $queryParams['when'] = $now->format('Y-m-d H:i');
        }

        if ($whereParts) {
            $whereClause = ' WHERE ' . join(' AND ', $whereParts);
        } else {
            $whereClause = '';
        }

        $posts = [];

        $sql = "SELECT id,`time`,subject,security, null as body,creatorId FROM blog_post $whereClause ORDER BY `time` DESC";
        $rows = $this->dbConnection->fetchAll($sql, $queryParams);
        foreach ($rows as $row) {
            $posts[] = $this->createPostFromDbRow($row);
        }
        return $posts;
    }

    /**
     * Create a post class from raw DB row / array
     *
     * @param $row
     * @return BlogPost
     */
    protected function createPostFromDbRow($row)
    {
        $creator = $this->app->getUserManager()->getUser($row['creatorId']);

        if (!$creator) {
            throw new InvalidParameterException("No such user");
        }

        $post = new BlogPost();
        $post->setId($row['id']);
        $post->setSubject($row['subject']);
        $post->setTime(new \DateTime($row['time']));
        $post->setBody($row['body']);
        $post->setSecurity($row['security']);

        $post->setCreator($creator);
        return $post;
    }
}
