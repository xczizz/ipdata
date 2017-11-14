<?php
/**
 * User: Mr-mao
 * Date: 2017/10/28
 * Time: 11:04
 */

namespace app\commands;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use app\libs\YanCrawler;
use Yii;

class HljController extends BaseController
{
    // 唯一爬虫前缀（记录日志, redis数据处理等时候使用）
    const crawler_prefix = 'hlipo:';

    // 登录cookie数据
    public $cookies;
    // 成功保存条数
    public $successCount = 0;

    /**
     * 生成登录cookie
     */
    public function getCookies()
    {
        if (empty($this->cookies)) {
            // 最多重试5次
            for ($i=0; $i <= 5; $i++) { 
                try {
                    $client = new \GuzzleHttp\Client([
                        'timeout' => 5,
                    ]);

                    $url = 'http://db.hlipo.gov.cn:8080/ipsss/login.do?' . rawurlencode('timeStamp='.date('D M d Y H:i:s')) .'GMT+0800'. rawurlencode(' (中国标准时间)') . '&username=guest&password=123456&totalPages=0';
                    $response = $client->request('GET', $url, [
                        'headers' => [
                            'Content-Type' => 'application/x-www-form-urlencoded',
                            'Referer' => 'http://db.hlipo.gov.cn:8080/ipsss/',
                            'Origin' => 'http://db.hlipo.gov.cn:8080',
                            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                            'Accept-Language' => 'zh-CN,zh;q=0.8',
                            'Connection' => 'keep-alive',
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36'
                        ]
                    ]);

                    $this->cookies = $response->getHeaders()['Set-Cookie'][0];
                    // 获取cookie成功，则跳出循环
                    break;
                } catch (\Exception $e) {

                }
            }
            if (!$this->cookies) {
                echo '获取cookie失败' . PHP_EOL;
                die;
            }
            echo '成功获取到cookies:'. $this->cookies . PHP_EOL;
        }

        return $this->cookies;
    }

    /**
     * 表单参数
     */
    public function formParams()
    {
        return [
            'area' => 'cn',
            'synonymous' => 'SYNONYM_UTF8',
            'presearchword' => 'null',
            'strWhere' => '申请（专利权）人=(哈尔滨工业大学)',
            'strSynonymous' => '1',
            'strSortMethod' => 'RELEVANCE',
            'strDefautCols' => '主权项, 名称, 摘要',
            'iHitPointType' => '115',
            'strChannels' => '14,15,16',
            'searchKind' => 'tableSearch',
            'trsLastWhere' => 'null',
            'strdb' => '14',
            'strdb' => '15',
            'strdb' => '16',
            'txt_I' => '哈尔滨工业大学',
        ];
    }

    /**
     * 爬取搜索列表
     */
    public function actionSearch($is_init = 1)
    {
        // 建立爬虫
        $crawler = new YanCrawler([
            'concurrency' => 3, // 并发线程数
            'is_init' => $is_init, // 是否初始化爬取队列
            'log_prefix' => self::crawler_prefix.'search', // 日志前缀
            'redis_prefix' => self::crawler_prefix.'search', // redis前缀
            'timeout' => 5.0,   // 爬取网页超时时间
            'log_step' => 5, // 每爬取多少页面记录一次日志
            'base_uri' => '',
            'retry_count' => 5,
            'queue_len' => '',
            'interval' => 0, // 爬取间隔时间
            'requests' => function () { // 需要发送的请求
                // 获取登录cookies
                $cookies = $this->getCookies();
                // 获取需要爬取的url
                $url = 'http://db.hlipo.gov.cn:8080/ipsss/overviewSearch.do?area=cn';
                // 爬取第一页数据，并获取总页码
                $total = $this->actionFirstPage();
                $pagerecord = 10; // 最多500
                $total_page = ceil($total / $pagerecord);
                // 从第二也数据开始爬取
                for ($i=2; $i <= $total_page; $i++) {
                    $request = [
                        'method' => 'post',
                        'uri' => $url,
                        'form_params' => array_merge($this->formParams(), [
                            'pageIndex' => $i,
                            'pagerecord' => $pagerecord
                        ]),
                        'headers' => [
                            'cookie' => $cookies,
                        ],
                        'callback_data' => [ // 回调参数
                            'url' => $url,
                        ],
                    ];
                    yield $request;
                }
            },
            'fulfilled' => function ($result, $request) { // 爬取成功的回调函数
                // 解析html并保存数据
                $this->filterHtml($result);
            },
            'rejected' => function ($request, $msg) { // 爬取失败的回调函数
            },
        ]);
        $result = $crawler->run();
        echo '爬取完毕: ' . date('y/m/d H:i:s') . PHP_EOL . '成功写入: ' . $this->successCount;
    }

    /**
     * 爬取第一页的数据并返回总页码
     */
    public function actionFirstPage($is_init = 1)
    {
        $total_page = 0;
        // 建立爬虫
        $crawler = new YanCrawler([
            'concurrency' => 1, // 并发线程数
            'is_init' => $is_init, // 是否初始化爬取队列
            'log_prefix' => self::crawler_prefix.'first-page', // 日志前缀
            'redis_prefix' => self::crawler_prefix.'first-page', // redis前缀
            'timeout' => 5.0,   // 爬取网页超时时间
            'log_step' => 5, // 每爬取多少页面记录一次日志
            'base_uri' => '',
            'retry_count' => 5,
            'queue_len' => '',
            'interval' => 0, // 爬取间隔时间
            'requests' => function () { // 需要发送的请求
                // 获取登录cookies
                $cookies = $this->getCookies();
                // 获取需要爬取的url
                $url = 'http://db.hlipo.gov.cn:8080/ipsss/overviewSearch.do?area=cn';
                $request = [
                    'method' => 'post',
                    'uri' => $url,
                    'form_params' => array_merge($this->formParams()),
                    'headers' => [
                        'cookie' => $cookies,
                    ],
                    'callback_data' => [ // 回调参数
                        'url' => $url,
                    ],
                ];
                yield $request;
            },
            'fulfilled' => function ($result, $request) use (&$total_page) { // 爬取成功的回调函数
                // 解析html并保存数据
                $total_page = $this->filterHtml($result);
            },
            'rejected' => function ($request, $msg) { // 爬取失败的回调函数
            },
        ]);
        $result = $crawler->run();
        echo "已成功爬取到总条数:" . $total_page . PHP_EOL;
        return $total_page;
    }

    /**
     * 解析html并保存数据
     */
    public function filterHtml($html)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);

        $span = $crawler->filter('#showDetail > .span9 > .row-fluid')
            ->reduce(function($node,$i){
                return ($i % 2 == 0);
            })->each(function($node){
                return $node->html();
            });
        foreach ($span as $key => $value) {
            // 专利号
            $number = (new Crawler($value))->filter('input')->attr('an');
            $number = str_replace('.', '', substr($number, 2));
            // 有效 无效
            $status = new Crawler();
            $status->addHtmlContent($value);
            $status = $status->filter('.checkbox > span')->last()->html();
            // 类型
            $type = new Crawler();
            $type->addHtmlContent($value);
            $type = $type->filter('.checkbox > span')->eq(1)->html(); // eq 写-2 不会被识别只能写 2 了
            // 标题
            $title = new Crawler();
            $title->addHtmlContent($value);
            $title = $title->filter('input')->attr('title');

            // input value的值，包括了公告号等信息
            $info = new Crawler();
            $info->addHtmlContent($value);
            $info = $info->filter('input')->attr('value');
            $info = substr_replace(str_replace(['\',',':\'','\'}'],['","','":"','"}'],$info), '"', 1,0); // 转化为php的可识别的json格式,这种替换方式可能出问题
            $info = json_decode($info, true);
            $publication_date = str_replace('.', '-', $info['pd']); // 公开日
            $publication_no = $info['pnm']; // 公开号
            $filing_date = str_replace('.', '-', $info['ad']); // 申请日

//                $sql = "INSERT INTO patent(application_no,general_status,patent_type,title,publication_date,publication_no,filing_date) VALUE('$number','$status','$type','$title','$publication_date','$publication_no','$filing_date') ON DUPLICATE KEY UPDATE general_status='$status',patent_type='$type',title='$title',publication_date='$publication_date',publication_no='$publication_no',filing_date='$filing_date'";
            $sql = "INSERT INTO patent(application_no,general_status,patent_type,title) VALUE('$number','$status','$type','$title') ON DUPLICATE KEY UPDATE general_status='$status',patent_type='$type',title='$title'";
            $rows = \Yii::$app->db->createCommand($sql)->execute();
            if ($rows) $this->successCount += 1;
        }
        // 解析总页数
        $total_page = $crawler->filter('#nav_keleyi_com > div > div > strong > div > div > div > ul.nav.pull-right > li:nth-child(5)')->text();
        $total_page = str_replace(['/',' '], '', $total_page);
        return $total_page;
    }


}
