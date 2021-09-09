# iseed-think

反向数据生成器是一个Thinkphp包，它提供了一种基于现有数据库表中的数据生成新种子文件的方法，用于ThinkPHP6+的。

## 安装

~~~
composer require cpyun/iseed-think
~~~

## 操作

示例
~~~
php think iseed my_table
~~~

~~~
php think iseed my_table,another_table
~~~

###类名前缀和类名后缀
为 Seeder 类名和文件名指定前缀或后缀。如果您想为具有现有种子的表创建额外的种子而不覆盖现有种子，这将非常有用。

~~~
php think iseed my_table --classnameprefix=Customized
~~~

