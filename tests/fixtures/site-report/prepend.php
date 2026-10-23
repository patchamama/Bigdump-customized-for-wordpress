<?php
namespace SiteReportFixture;
use Exception;
// The isolated source copy shares this namespace, so no real PDO constructor is used.
class PDO {
    const ATTR_ERRMODE = 3, ERRMODE_EXCEPTION = 2, ATTR_TIMEOUT = 2, FETCH_ASSOC = 2;
    public function __construct(...$args) {
        if (isset($_GET['offline']) || !is_file(__DIR__ . '/wp-config.php')) throw new Exception('SYNTHETIC_DB_SECRET');
    }
    public function query($sql) {
        if (!preg_match('/^(SELECT|SHOW) /i', $sql)) throw new Exception('Non-read-only SQL');
        if (str_contains($sql, 'VERSION()')) return new ReportRows([['8.0.40']]);
        if (isset($_GET['query_failure'])) throw new Exception('CONFIG_SECRET');
        if (str_contains($sql, 'information_schema.TABLES')) return new ReportRows([['table_name'=>'wp_posts','engine'=>'InnoDB','table_rows'=>12,'data_length'=>2048,'index_length'=>1024]]);
        if (str_contains($sql, 'COUNT(*)')) return new ReportRows([[12]]);
        $options = ['siteurl'=>'https://someone:URL_SECRET@WP.Example.test/sub?token=URL_SECRET', 'home'=>'https://wp.example.test', 'blog_public'=>'0', 'permalink_structure'=>'/%postname%/', 'users_can_register'=>'0', 'default_role'=>'subscriber', 'timezone_string'=>'UTC', 'stylesheet'=>'sample', 'template'=>'sample', 'active_plugins'=>serialize(['sample/main.php'])];
        if (str_contains($sql, 'option_name IN')) {
            if (isset($_GET['missing_options'])) return new ReportRows([]);
            if (isset($_GET['unknown_role'])) { $options['users_can_register'] = '1'; $options['default_role'] = 'unrecognized'; }
            $rows = [];
            foreach ($options as $key=>$value) $rows[] = ['option_name'=>$key,'option_value'=>$value];
            return new ReportRows($rows);
        }
        if (str_contains($sql, "option_name='siteurl'")) return new ReportRows([[$options['siteurl']]]);
        if (str_contains($sql, "option_name='blog_public'")) return new ReportRows([['0']]);
        throw new Exception('Unexpected query: ' . $sql);
    }
    public function quote($value) { return "'" . str_replace("'", "''", $value) . "'"; }
}
class ReportRows {
    private $rows;
    public function __construct($rows) { $this->rows = $rows; }
    public function fetch($mode = null) { return array_shift($this->rows) ?: false; }
    public function fetchAll($mode = null) { return $this->rows; }
    public function fetchColumn($column = 0) { $row = $this->fetch(); return $row ? array_values($row)[$column] : false; }
}
