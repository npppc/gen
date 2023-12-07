<?php
namespace Npc\Gen\Entity;

use Npc\Entity\Base;

/**
 * Class Config
 * @package Npc\Gen\Entity
 *
 * @property string $namespace 命名空间
 * @property string $path 根目录
 * @property bool $genApplication 生成 application
 * @property string $applicationPath application 路径
 * @property bool $genDomain 生成 domain
 * @property string $domainPath domain 路径
 * @property Database $database 数据库配置
 * @property bool genModel 生成 model
 * @property string $modelPath model 目录
 *
 * @property string $json rap2 doc json content
 * @property array $sdk rap2 sdk 配置
 */
class Config extends Base
{

}