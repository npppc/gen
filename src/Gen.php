<?php

namespace Npc\Gen;

use Npc\Gen\Entity\Stub;
use Npc\Gen\Entity\Config;
use Npc\Helper\Phalcon\Mysql;
use Cake\Collection\Collection;
use Phalcon\Loader;

class Gen
{
    /**
     * @var Config $config
     */
    protected $config;
    /**
     * @var Mysql $db
     */
    protected $db;

    public static $url_prefix = ['/'];
    public static $table_prefix = [];
    public static $data_fields = [];

    public const ARRAY_FLAG = '[]';

    const TYPE_DEFINE = [
        'int' => '数字 int',
        'bigint' => '超大数字 bigint',
        'decimal' => '金额计算 decimal',
        'float' => '浮点数 float',

        'varchar' => '字符串 varchar',
        'text' => '短文本 text 64KiB',
        'mediumtext' => '长文本 mediumtext 16MiB',
        'longtext' => '超长文本 longtext 4GiB',

        'date' => '年-月-日 date ',
        'datetime' => '年-月-日 时:分:秒 datetime',

        'char' => 'char',
        'tinyint' => 'tinyint',
        'enum' => '枚举 enum',
        'json' => 'json',
        'timestamp' => '时间戳 timestamp',
    ];

    const TYPE_MAPPER = [
        'int' => 'int',
        'bigint' => 'int',
        'decimal' => 'int',
        'float' => 'int',

        'varchar' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',

        'date' => 'string',
        'datetime' => 'string',

        'char' => 'string',
        'tinyint' => 'string',
        'enum' => 'string',
        'json' => 'string',
        'timestamp' => 'string',
    ];

    public static function normalize($table = '', $prefix = 'A')
    {
        return preg_match('#^([\d]+)#ism', $table) ? $prefix . trim($table) : trim($table);
    }

    public static function ucwords(string $str,string $split = '_')
    {
        return str_replace(' ','',ucwords(str_replace($split, ' ', $str)));
    }

    public static function before(string $subject, string $search)
    {
        return $search === '' ? $subject : explode($search, $subject)[0];
    }

    public static function afterLast(string $subject, string $search)
    {
        $position = strrpos($subject, $search);

        if ($position === false) {
            return $subject;
        }

        return substr($subject, $position + strlen($search));
    }

    public static function trims(string $str)
    {
        return addslashes(str_replace(["\n"],'',$str));
    }

    public static function contains(string $target,string $search)
    {
        return stripos($target,$search) !== false;
    }

    public function handle(Config $config)
    {
        $this->config = $config;
        $this->mkdir();

        //生成DAO层代码
        if($this->config->database && $this->config->genDomain)
        {
            $this->genModels();
        }

        //根据rap2文档生成应用层代码
        if($this->config->json)
        {
            $this->genApplication();
        }

        //生成SDK代码
        if($this->config->sdk)
        {
            $this->copyFile('domain_service_base_sdk.stub',$this->config->domainPath.'/BaseService.php',new Stub(
                array_merge($this->config->sdk, ['namespace' => $this->config->namespace])
            ));
        }
    }

    protected function mkdir()
    {
        @mkdir($this->config->path);
        @mkdir($this->config->path.'/Application');
        @mkdir($this->config->path.'/Domain');
        @mkdir($this->config->path.'/Model');
        $this->config->applicationPath = $this->config->path .'/Application/';
        $this->config->domainPath = $this->config->path .'/Domain/';
        $this->config->modelPath = $this->config->path .'/Model/';
        @mkdir($this->config->applicationPath);
        @mkdir($this->config->domainPath);
        @mkdir($this->config->modelPath);

        $loader = new Loader();
        $loader->registerNamespaces([
            $this->config->namespace.'\\Application\\Service' => $this->config->applicationPath.'/Service',
            $this->config->namespace.'\\Domain' => $this->config->domainPath,
        ]);
        $loader->register();

        @mkdir($this->config->applicationPath.'/Entity');
        //生成 Qo\Base
        @mkdir($this->config->applicationPath.'/Request');
        $this->copyFile('application_request_base.stub',$this->config->applicationPath.'/Request/Base.php',new Stub([
            'namespace' => $this->config->namespace,
        ]));

        //生成 Service\Base
        @mkdir($this->config->applicationPath.'/Service');
        $this->copyFile('application_service_base.stub',$this->config->applicationPath.'/Service/Base.php',new Stub([
            'namespace' => $this->config->namespace,
        ]));

        $this->copyFile('domain_service_base.stub',$this->config->domainPath.'/BaseService.php',new Stub([
            'namespace' => $this->config->namespace
        ]));
    }

    protected function genModels()
    {
        $this->db = new Mysql($this->config->database->toArray());
        $tables = $this->db->showTables($this->config->database->dbname);
        foreach ($tables as $table)
        {
            //生成 models
            $this->genModel($table['TABLE_NAME'],$table['TABLE_COMMENT']);
        }
    }

    protected function genApplication()
    {
        $apis = json_decode($this->config->json, true, 512);
        if (isset($apis['data'])) {
            $services = [];
            foreach ($apis['data']['modules'] as $module) {
                foreach ($module['interfaces'] as $interface) {
                    $api = $interface['url'];
                    $this->resolveUrl($interface);
                    $className = $this->translateUrlToClassName($interface['url']);
                    list($controller, $action) = explode('/', $className);
                    $stub = new Stub();
                    $stub->namespace = $this->config->namespace;
                    $stub->class = $controller;
                    $stub->method = lcfirst($action);
                    $stub->entity = str_replace('/', '', $className);
                    $stub->request = str_replace('/', '', $className) . 'Request';
                    $stub->method_name = $interface['name'];
                    $stub->api = $api;
                    $collection = new Collection($interface['properties']);
                    $key = $collection->indexBy('id')->toArray();
                    $group = $collection->groupBy('parentId')->toArray();
                    foreach ($key as $k => $v) {
                        if (isset($group[$k]))
                        {
                            if(isset($group[$v['parentId']]) && isset($key[$v['parentId']]))
                            {
                                $entityName = $this->translateEntityTypeToClassName(
                                        $key[$v['parentId']]['type']
                                    ).self::ucwords($v['name']);
                            }
                            else
                            {
                                $entityName = $stub->entity;
                                $entityName = in_array(
                                    $v['name'],
                                    self::$data_fields
                                ) ? $entityName : $entityName.self::ucwords($v['name']);
                            }

                            $entityName .= 'Entity';
                            $type = $entityName;
                            if (strtolower($v['type']) == 'array') {
                                $type = $entityName.self::ARRAY_FLAG;
                            }
                            $key[$k]['type'] = $type;
                        }
                    }

                    $collection = new Collection($key);
                    $group = $collection->groupBy('parentId')->toArray();
                    $this->genApplicationRequest($collection->match(['scope' => 'request']), $stub, $group);
                    $this->genApplicationEntity($collection->match(['scope' => 'response']), $stub, $group);

                    $services[$controller][$action] = $stub;
                }
            }
            foreach($services as $service => $methods)
            {
                if(empty($service)) continue;
                //fix
                if($service == 'Public')
                {
                    $service = 'Common';
                }
                $this->config->genApplication && $this->genApplicationService($service,$methods);
                $this->config->genDomain && $this->genDomainService($service,$methods);
            }
        }
    }

    /**
     * 解析 指定文件 获取 use methods
     * @param string $target
     * @param string $class
     * @return array[]
     */
    protected function reflectionClass(string $target,string $class)
    {
        $methods = [];
        try{
            $reflict = new \ReflectionClass($class);
            //反射获取方法
            foreach($reflict->getMethods() as $method)
            {
                $methods[] = $method->getName();
            }
        }
        catch (\Throwable $e)
        {

        }
        preg_match_all('#use ([^;]*);#ism',file_get_contents($target),$matches);

        return [$methods,(array)$matches[1]];
    }

    /**
     * 生成 application service
     * @param string $service
     * @param array $methods
     */
    protected function genApplicationService(string $service,array $methods)
    {
        $funcCollection = new Collection($methods);
        $stub = new Stub();
        $stub->namespace = $this->config->namespace;
        $stub->class = $service;

        $target = $this->config->applicationPath.'/Service/'.$service.'Service.php';
        $_methods = [];
        $_use = [];
        //当目标存在
        if(file_exists($target))
        {
            $class = $this->config->namespace.'\\Application\\Service\\'.$service.'Service';
            list($_methods,$_use) = $this->reflectionClass($target,$class);
        }

        //解析 Use
        $stub->use = implode(
            "\n",
            $funcCollection->map(function (Stub $func) use ($_use) {
                $request = "{$func->namespace}\\Application\Request\\{$func->request}";
                $entity = "{$func->namespace}\\Application\Entity\\{$func->entity}";

                if(in_array($request,$_use))
                {
                    $request = '';
                }
                if(in_array($entity,$_use))
                {
                    $entity = '';
                }
                //当响应体存在
                if(class_exists($entity))
                {
                    return ($request ? "use $request;\n" : '') . ($entity ? "use $entity;\n" : '');
                }
                else
                {
                    return ($request ? "use $request;\n" : '');
                }
            })->filter()->toArray()
        );

        //解析 methods
        $stub->methods = implode(
            "\t\n\n\t",
            $funcCollection->map(function (Stub $func) use ($_methods){
                $entity = "{$func->namespace}\\Application\Entity\\{$func->entity}";
                if(class_exists($entity))
                {
                    return in_array($func->method, $_methods) ? '' : <<<SET
/**
     * {$func->method_name}
     * @param {$func->request} \$request
     * @return {$func->entity}
     * @throws Exception
     */
    public function {$func->method}({$func->request} \$request) : {$func->entity}
    {
        \$data = \$this->domainService->{$func->method}(\$request->toDomainEntity());
        return new {$func->entity}(\$data);
    }
SET;
                }
                else
                {
                    return in_array($func->method, $_methods) ? '' : <<<SET
/**
     * {$func->method_name}
     * @param {$func->request} \$request
     * @return mixed
     * @throws Exception
     */
    public function {$func->method}({$func->request} \$request)
    {
        return \$this->domainService->{$func->method}(\$request->toDomainEntity());
    }
SET;
                }
            })->filter()->toArray()
        );

        if(file_exists($target))
        {
            $stub->use = '#use#' . ($stub->use ? "\n" . $stub->use : '');
            $stub->methods = '#methods#' . ($stub->methods ? "\n\t" . $stub->methods . "\n" : '');
            $this->modifyFile($target,$stub);
        }
        else
        {
            $this->copyFile('application_service.stub',$target,$stub);
        }
    }

    /**
     * 生成 domain service
     * @param string $service
     * @param array $methods
     */
    protected function genDomainService(string $service,array $methods)
    {
        $funcCollection = new Collection($methods);
        $stub = new Stub();
        $stub->namespace = $this->config->namespace;
        $stub->class = $service;

        $target = $this->config->domainPath.'/'.$service.'/'.$service.'Service.php';
        $_methods = [];
        $_use = [];
        //当目标存在
        if(file_exists($target))
        {
            $class = $this->config->namespace.'\\Domain\\'.$service.'\\'.$service.'Service';
            list($_methods,$_use) = $this->reflectionClass($target,$class);
        }

        //解析 Use
        $use = '';
        $entity = $this->config->namespace.'\\Domain\\'.$service.'\\'.$service;
        if(class_exists($entity.'Entity') && !in_array($entity.'Entity',$_use))
            $use .= 'use '.$entity.'Entity;'."\n";
        if(class_exists($entity.'Repository') && !in_array($entity.'Repository',$_use))
            $use .= 'use '.$entity.'Repository;'."\n";
        $stub->use = $use;

        //解析 methods TODO 这里要不要用 Qo 传递
        $stub->methods = implode(
            "\t\n\n\t",
            $funcCollection->map(function (Stub $func) use ($_methods) {
                return in_array($func->method,$_methods) ? '' : <<<SET
/**
     * {$func->method_name}
     * @param Entity \$query
     * @return mixed
     * @throws Exception
     */
    public function {$func->method}(Entity \$query)
    {
        return \$this->query('{$func->api}',\$query);
    }
SET;
            })->filter()->toArray()
        );

        @mkdir($this->config->domainPath.'/'.$service);
        if(file_exists($target))
        {
            $stub->use = '#use#' . ($stub->use ? "\n" . $stub->use: '' );
            $stub->methods =  '#methods#' . ($stub->methods ? "\n\t" . $stub->methods . "\n" : '');
            $this->modifyFile($target,$stub);
        }
        else
        {
            $this->copyFile('domain_service.stub',$target,$stub);
        }
    }

    /**
     * 响应结果集生成对应的 entity 貌似没啥用
     *
     * @param Collection $respProperties
     * @param Stub $stub
     * @param array $group
     */
    protected function genApplicationEntity(Collection $respProperties, Stub $stub, array $group)
    {
        $respProperties->each(function ($property) use ($group, $stub) {
            if ($property['parentId'] != -1) {
                return null;
            }
            return $this->resolveProperty($property, $group, $stub);
        });
    }

    /**
     * 生成 QueryObject 文件
     * @param Collection $reqProperties
     * @param Stub $stub
     * @param array $group
     */
    protected function genApplicationRequest(Collection $reqProperties, Stub $stub, array $group)
    {
        //解析 Use
        $stub->use = implode(
            "\n",
            $reqProperties->map(function ($property) use ($stub) {
                if (self::contains($property['type'], 'Entity')) {
                    $property['type'] = $this->translateEntityTypeToClassName($property['type']);
                    return <<<SET
use {$stub->namespace}\\Application\\Entity\\{$property['type']};
SET;
                }
            })->filter()->toArray()
        );

        //解析 properties
        $stub->property = implode(
            "\r\n",
            $reqProperties->map(function ($property) use ($group, $stub) {
                if ($property['parentId'] != -1) {
                    return null;
                }
                return $this->resolveProperty($property, $group, $stub);
            })->filter()->toArray()
        );

        //解析 参数规则
        $stub->rules = implode(
            "\t\t\n\t\t",
            $reqProperties->map(function ($property) use ($stub) {
                if ($property['parentId'] != -1) {
                    return null;
                }
                $required = $property['required'] ? 'required' : '';
                $desc = self::trims($property['description']);

                return <<<SET
'{$property['name']}' => ['{$required}','{$desc}'],
SET;
            })->filter()->toArray()
        );

        $this->copyFile('application_request.stub',$this->config->applicationPath.'/Request/'.$stub->request.'.php',$stub);
    }

    /**
     * 转换字段类型.
     *
     * @param $type
     *
     * @return string
     */
    protected function translatePropertyType($type)
    {
        if (self::contains($type, 'Entity')) {
            return str_replace('Entity','',$type);
        }
        $type = strtolower($type);

        switch ($type) {
            case 'number':
                return 'int';

            case 'boolean':
                return 'bool';
        }

        return $type;
    }

    /**
     * 创建一个实体文件
     *
     * @param array $property 属性
     * @param array $properties 下属属性列表
     * @param array $group 分组后的属性列表
     * @param Stub $stub 模板信息
     */
    protected function genEntity(array $property,array $properties,array $group,Stub $stub) {
        // 处理下属属性
        $stub->property = implode(
            "\r\n",
            (new Collection($properties))->map(function ($property) use ($group, $stub) {
                return $this->resolveProperty($property, $group, $stub);
            })->toArray()
        );

        $stub->entity = $this->translateEntityTypeToClassName($property['type']);

        $stub->sets = implode(
            "\r\n\r\n",
            (new Collection($properties))->map(function ($property) use ($stub) {
                $name = $property['name'];
                $ucName = (substr($name,0,1) == '_' ? '_' : '').self::ucwords($name);

                return <<<SET
    public function set{$ucName}(\$value)
    {
        \$this->_data['{$name}'] = \$value;

        return \$this;
    }
SET;
            })->filter()->toArray()
        );

        $this->copyFile('application_entity.stub',$this->config->applicationPath.'/Entity/'.$stub->entity.'.php',$stub);
    }

    /**
     * 将实体类型转换成类名.
     *
     * @param $entityType
     *
     * @return array|string|string[]
     */
    protected function translateEntityTypeToClassName($entityType)
    {
        return str_replace([self::ARRAY_FLAG,'Entity'], '', $entityType);
    }


    /**
     * 处理一个属性
     *
     * @param array $property 当前属性
     * @param array $group 分组后的属性列表
     * @param Stub $stub 模板信息
     * @return string 当前属性信息
     */
    protected function resolveProperty(array $property, array $group, Stub $stub)
    {
        if (self::contains($property['type'], 'Entity')) {
            $this->genEntity(
                $property,
                $group[$property['id']],
                $group,
                $stub
            );
        }
        $name = $property['name'];
        $type = self::afterLast($this->translatePropertyType($property['type']),'/');
        $desc = self::trims($property['description']);

        return <<<PRO
 * @property {$type} \${$name} {$desc}
PRO;
    }

    /**
     * 将Url转换成className.
     *
     * @param $url
     *
     * @return string
     */
    protected function translateUrlToClassName($url)
    {
        return self::ucwords(trim(str_ireplace(self::$url_prefix,'/ ',self::before($url, '?')),'/'));
    }

    protected function resolveUrl(&$interface)
    {
        $url = $interface['url'];
        $matches = null;
        if (preg_match_all("/{(\w+)}|:(\w+)/", $url, $matches)) {
            $params = empty(array_filter($matches[2])) ? $matches[1] : $matches[2];
            $interface['url'] = str_replace($matches[0], $params, $url);
            $collection = collect($interface['properties']);
            foreach ($params as $param) {
                if ($collection->contains('name', $param)) {
                    continue;
                }
                $interface['properties'][] = [
                    'id' => $param,
                    'name' => $param,
                    'type' => 'string',
                    'parentId' => -1,
                    'scope' => 'request',
                    'description' => '',
                ];
            }

        }
    }

    /**
     * 根据给定的数据库表 生成 model、modelModel 到 models 目录
     * @param string $table
     * @param string $name
     * @return bool
     */
    protected function genModel(string $table,string $name)
    {
        //获取表定义
        $definitions = $this->db->showFullFieldsAssociate($table);

        $stub = new Stub();
        $stub->namespace = $this->config->namespace;
        $stub->class = self::ucwords(str_ireplace(self::$table_prefix,'',self::normalize($table)));
        $stub->source = $table;
        $stub->property = '';
        $stub->properties = '';
        $stub->resource_name = $name;

        //生成属性
        foreach($definitions as $filed => $definition)
        {
            $stub->property .= "\n".' * @property '.(self::TYPE_MAPPER[$definition['Type']] ?? 'string').' $'.$filed.' '.$definition['comment'];
            $stub->properties .= "\n\t".'public  $'.$filed.'; //'.$definition['comment'];
        }

        //生成基础 model 此 model 可以随时根据数据库结构刷新
        $this->copyFile('model_model.stubs',$this->config->modelPath.'/'.$stub->class.'Model.php',$stub);

        //生成二层 model 文件
        if(!file_exists($this->config->modelPath.'/'.$stub->class.'.php'))
        {
            $this->copyFile('model.stubs',$this->config->modelPath.'/'.$stub->class.'.php',$stub);
        }


        $stub->property = '';
        foreach($definitions as $filed => $definition)
        {
            $stub->property .= "\n".' * @property '.$this->guessEntityType($definition).' $'.$filed.' '.$definition['comment'];
            $stub->properties .= "\n\t".'public  $'.$filed.'; //'.$definition['comment'];
        }

        //TODO 有无 id 问题
        @mkdir($this->config->domainPath.'/'.$stub->class);
        //if(!file_exists($this->config->domainPath.'/'.$stub->class.'/'.$stub->class.'Entity.php'))
        {
            $this->copyFile('domain_entity.stubs',$this->config->domainPath.'/'.$stub->class.'/'.$stub->class.'Entity.php',$stub);
        }
        if(!file_exists($this->config->domainPath.'/'.$stub->class.'/'.$stub->class.'Repository.php'))
        {
            $stub->use = 'use '.$this->config->namespace.'\\Model\\'.$stub->class.';';
            $this->copyFile('domain_repository.stubs',$this->config->domainPath.'/'.$stub->class.'/'.$stub->class.'Repository.php',$stub);
        }

        return true;
    }

    public function guessEntityType(array $definition)
    {

        preg_match_all('#.*?(?:\s([^:]*?):([^\s:]*))#',$definition['Comment'],$matches);
        if($definition['ID'] == 'pay_bankinfo')
        {
            //var_export($definition['Comment']);
            //var_export($matches);
        }
        return self::TYPE_MAPPER[$definition['Type']] ?? 'string';
    }

    /**
     * 根据模版文件生成指定文件
     * @param string $tpl 模版文件
     * @param string $target 输出文件
     * @param Stub $info 替换的信息
     */
    protected function copyFile(string $tpl, string $target = '', Stub $info)
    {
        $info = $info->toArray();
        $keys = array_keys($info);
        $values = array_values($info);
        array_walk($keys,function (&$val,$key){ $val = '%'.$val.'%';});
        $source = file_get_contents(__DIR__. '/Stubs/'.$tpl);
        $content = str_replace(
            $keys,
            $values,
            $source
        );
        file_put_contents($target,$content);
    }

    /**
     * @param string $target
     * @param Stub $info
     */
    protected function modifyFile(string $target = '', Stub $info)
    {
        $info = $info->toArray();
        $keys = array_keys($info);
        $values = array_values($info);
        array_walk($keys,function (&$val,$key){ $val = '#'.$val.'#';});
        $source = file_get_contents($target);
        $content = str_replace(
            $keys,
            $values,
            $source
        );
        file_put_contents($target,$content);
    }
}