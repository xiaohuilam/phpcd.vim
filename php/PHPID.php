<?php
namespace PHPCD;

use Psr\Log\LoggerInterface as Logger;
use Lvht\MsgpackRpc\Server as RpcServer;
use Lvht\MsgpackRpc\Handler as RpcHandler;
use Lvht\Key\Key;

class PHPID implements RpcHandler
{
    /**
     * @var RpcServer
     */
    private $server;

    /**
     * @var Logger
     */
    private $logger;

    private $root;

    /**
     * @var Key
     */
    private $db;

    private $class_path = [];
    private $class_path_count = 0;

    public function __construct($root, Logger $logger)
    {
        $this->root = $root;
        $this->logger = $logger;
        $this->db = Key::new($root.'/.phpcd.db');
    }

    public function setServer(RpcServer $server)
    {
        $this->server = $server;
    }

    /**
     * update index for one class
     *
     * @param string $class_name fqdn
     */
    public function update($class_name)
    {
        list($parent, $interfaces) = $this->getClassInfo($class_name);

        if ($parent) {
            $this->updateParentIndex($parent, $class_name);
        }
        foreach ($interfaces as $interface) {
            $this->updateInterfaceIndex($interface, $class_name);
        }
    }

    /**
     * Fetch an interface's implemation list,
     * or an abstract class's child class.
     *
     * @param string $name name of interface or abstract class
     * @param bool $is_abstract_class
     *
     * @return [
     *   'full class name 1',
     *   'full class name 2',
     * ]
     */
    public function ls($name, $is_abstract_class = false)
    {
        $base_path = $is_abstract_class ? $this->getIntefacesDir()
            : $this->getExtendsDir();
        $path = $base_path . $this->getIndexFileName($name);
        return (array) $this->db->get($path);
    }

    /**
     * Fetch and save class's interface and parent info
     * according the autoload_classmap.php file
     *
     * @param bool $is_force overwrite the exists index
     */
    public function index()
    {
        exec('composer dump-autoload -o -d ' . $this->root . ' 2>&1 >/dev/null');
        $class_path = require $this->root
            . '/vendor/composer/autoload_classmap.php';
        foreach ($class_path as $class => $path) {
            $this->class_path[] = [$class, $path];
        }
        $this->class_path_count = count($class_path);

        $this->vimOpenProgressBar($this->class_path_count);

        $pipe_path = tempnam(sys_get_temp_dir(), 'phpcd');
        while ($this->class_path_count) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                die('could not fork');
            } elseif ($pid > 0) {
                // 父进程
                pcntl_waitpid($pid, $status);
                $this->class_path_count = file_get_contents($pipe_path);
            } else {
                // 子进程
                register_shutdown_function(function () use ($pipe_path) {
                    file_put_contents($pipe_path, $this->class_path_count);
                });
                $this->_index();
                file_put_contents($pipe_path, "0");
                exit;
            }
        }
        unlink($pipe_path);
        $this->vimCloseProgressBar();
    }

    private function getIntefacesDir()
    {
        return 'i:';
    }

    private function getExtendsDir()
    {
        return 'e:';
    }

    private function _index()
    {
        while (--$this->class_path_count) {
            $this->vimUpdateProgressBar();
            list($class_name, $file_path) = $this->class_path[$this->class_path_count];

            $this->update($class_name);
        }
    }

    private function updateParentIndex($parent, $child)
    {
        $index_file = $this->getExtendsDir() . $this->getIndexFileName($parent);
        $this->saveChild($index_file, $child);
    }

    private function updateInterfaceIndex($interface, $implementation)
    {
        $index_file = $this->getIntefacesDir() . $this->getIndexFileName($interface);
        $this->saveChild($index_file, $implementation);
    }

    private function saveChild($index_file, $child)
    {
        $childs = (array) $this->db->get($index_file);

        if (!in_array($child, $childs)) {
            $childs[] = $child;
            $this->db->set($index_file, $childs);
        }
    }

    private function getIndexFileName($name)
    {
        return $name;
    }

    private function getClassInfo($name) {
        try {
            $reflection = new \ReflectionClass($name);

            $parent = $reflection->getParentClass();
            if ($parent) {
                $parent = $parent->getName();
            }

            $interfaces = array_keys($reflection->getInterfaces());

            return [$parent, $interfaces];
        } catch (\ReflectionException $e) {
            return [null, []];
        }
    }

    private function vimOpenProgressBar($max)
    {
        $cmd = 'let g:pb = vim#widgets#progressbar#NewSimpleProgressBar("Indexing:", ' . $max . ')';
        $this->server->call('vim_command', [$cmd]);
    }

    private function vimUpdateProgressBar()
    {
        $this->server->call('vim_command', ['call g:pb.incr()']);
    }

    private function vimCloseProgressBar()
    {
        $this->server->call('vim_command', ['call g:pb.restore()']);
    }
}
