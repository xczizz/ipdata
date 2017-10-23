<?php
/**
 * User: Mr-mao
 * Date: 2017/10/22
 * Time: 11:31
 */


namespace app\commands;

use app\models\ChangeOfBibliographicData;
use app\models\OverdueFine;
use app\models\PaidFee;
use app\models\UnpaidFee;
use GuzzleHttp\Psr7\Response;
use Yii;
use yii\console\Controller;
use app\models\Patent;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Symfony\Component\DomCrawler\Crawler;

class SpiderController extends Controller
{
    public $queue = [];

    public function actionIndex()
    {
        $start = $_SERVER['REQUEST_TIME'];  // 开始时间
        $this->stdout('Start time:' . date('H:i:s',$start) . PHP_EOL);
        $this->queue = Patent::find()->select(['application_no'])->asArray()->all();

        do {
            $patents_list = [];
            for ($i = 0; $i < 5 ; $i++) {
                $patents_list[] = array_shift($this->queue);
            }
            $this->crawlBasicInfo(array_filter($patents_list));
        } while (!empty($this->queue));

        $this->queue = Patent::find()->select(['application_no'])->asArray()->all();
        do {
            $patents_list = [];
            for ($i = 0; $i < 5 ; $i++) {
                $patents_list[] = array_shift($this->queue);
            }
            $this->crawlPublicationInfo(array_filter($patents_list));
        } while (!empty($this->queue));

        $this->queue = Patent::find()->select(['application_no'])->asArray()->all();
        do {
            $patents_list = [];
            for ($i = 0; $i < 5 ; $i++) {
                $patents_list[] = array_shift($this->queue);
            }
            $this->crawlPaymentInfo(array_filter($patents_list));
        } while (!empty($this->queue));

        $this->stdout('Time Consuming:' . (time() - $start) . ' seconds' . PHP_EOL);

    }

    /**
     * 爬取费用信息
     *
     * @param array $application_no
     */
    public function crawlPaymentInfo(array $application_no)
    {
        $base_uri = 'http://cpquery.sipo.gov.cn/txnQueryFeeData.do';
        $concurrency = count($application_no);
        $client = new Client([
            'headers' => [
                'User-Agent' => $this->getUA(),
            ],
            'proxy' => $this->getIP(),
            'cookies' => true,
            'timeout' => 60,
            'allow_redirects' => false,
            'connect_timeout' => 60,
        ]);
        $requests = function ($total) use ($base_uri, $application_no, $client) {
            foreach ($application_no as $patent) {
                yield function() use ($patent, $base_uri, $client) {
                    return $client->getAsync($base_uri . '?select-key:shenqingh=' . $patent['application_no']);
                };
            }
        };
        $patent_list = array_values($application_no);
        $pool = new Pool($client, $requests($concurrency), [
            'concurrency' => $concurrency,
            'fulfilled' => function (Response $response, $index) use ($patent_list) {
                if ($response->getStatusCode() == 200) {
                    $html = $response->getBody()->getContents();
                    if ($html === '') {
                        $this->stdout($patent_list[$index]['application_no'] . ' is null' .PHP_EOL);
                    } else {
                        $result = $this->parsePaymentInfo($html);
                        $this->savePaymentInfo($result, $patent_list[$index]['application_no']);
                        $this->stdout($patent_list[$index]['application_no'] . ' OK'.PHP_EOL);
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($patent_list) {

                $this->stdout('Error occurred time:' . date('H:i:s',time()) . PHP_EOL);
                $this->stdout('Error No:' . $patent_list[$index]['application_no'] . ' Reason:' . $reason . PHP_EOL);
                // this is delivered each failed request
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();
    }

    /**
     * 解析费用信息页面
     *
     * @param $html
     * @return array
     */
    public function parsePaymentInfo($html)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $last_span = $crawler->filter('body > span')->last();
        if (!$last_span->count()) {
            $this->stdout('Error: empty node'.PHP_EOL.'Source code: '.$html);
            return [
                'unpaid_fee' => [],
                'paid_fee' => [],
                'overdue_fine' => []
            ];
        } else {
            $key = $last_span->attr('id');
        }
        $useful_id = array_flip($this->decrypt($key));

        // 应缴费信息
        $trHtml = $crawler->filter('#djfid')->filter('tr')->each(function (Crawler $node) {
            return $node->html();
        });
        $unpaid_fee = [];
        foreach ($trHtml as $idx => $tr) {
            if ($idx !== 0) {
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $type = $trCrawler->filter('span[name="record_yingjiaof:yingjiaofydm"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $amount = $trCrawler->filter('span[name="record_yingjiaof:shijiyjje"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text(); // 默认的else{return NULL}
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $date = $trCrawler->filter('span[name="record_yingjiaof:jiaofeijzr"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $unpaid_fee[] = [
                    'type' => implode('', $type),
                    'amount' => implode('', $amount),
                    'due_date' => implode('', $date)
                ];
            }
        }

        // 已缴费信息
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $trHtml = $crawler->filter('#yjfid')->filter('tr')->each(function (Crawler $node) {
            return $node->html();
        });
        $paid_fee = [];
        foreach ($trHtml as $idx => $tr) {
            if ($idx !== 0) {
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $type = $trCrawler->filter('span[name="record_yijiaof:feiyongzldm"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $amount = $trCrawler->filter('span[name="record_yijiaof:jiaofeije"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $receipt_no = $trCrawler->filter('span[name="record_yijiaof:shoujuh"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $payer = $trCrawler->filter('span[name="record_yijiaof:jiaofeirxm"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $date = $trCrawler->filter('span[name="record_yijiaof:jiaofeisj"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $paid_fee[] = [
                    'type' => implode('', $type),
                    'amount' => implode('', $amount),
                    'paid_date' => implode('', $date),
                    'paid_by' => implode('', $payer),
                    'receipt_no' => implode('', $receipt_no),
                ];
            }
        }

        // 滞纳金信息
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $trHtml = $crawler->filter('#znjid')->filter('tr')->each(function (Crawler $node) {
            return $node->html();
        });
        $overdue_fine = [];
        foreach ($trHtml as $idx => $tr) {
            if ($idx !== 0) {
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $date = $trCrawler->filter('span[name="record_zhinaj:jiaofeisj"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $original_amount = $trCrawler->filter('span[name="record_zhinaj:shijiaojesznd"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $fine_amount = $trCrawler->filter('span[name="record_zhinaj:shijiaoje"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $total_amount = $trCrawler->filter('span[name="record_zhinaj:zongji"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr("id")])) {
                            return $node->text();
                        }
                    }
                );

                $overdue_fine[] = [
                    'due_date' => implode('', $date),
                    'original_amount' => (int)implode('', $original_amount),
                    'fine_amount' => (int)implode('', $fine_amount),
                    'total_amount' => (int)implode('', $total_amount)
                ];
            }
        }

        return [
            'unpaid_fee' => $unpaid_fee,
            'paid_fee' => $paid_fee,
            'overdue_fine' => $overdue_fine
        ];
    }

    /**
     * 保存费用信息
     *
     * @param $data
     * @param $application_no
     */
    public function savePaymentInfo($data, $application_no)
    {
        $patent_id = Patent::findOne(['application_no' => $application_no])->id;
        foreach ($data['unpaid_fee'] as $value) {
            $unpaid_model = new UnpaidFee();
            $unpaid_model->patent_id = $patent_id;
            $unpaid_model->type = $value['type'];
            $unpaid_model->amount = $value['amount'];
            $unpaid_model->due_date = $value['due_date'];
            $unpaid_model->save();
        }
        foreach ($data['paid_fee'] as $value) {
            $paid_model = new PaidFee();
            $paid_model->patent_id = $patent_id;
            $paid_model->type = $value['type'];
            $paid_model->amount = $value['amount'];
            $paid_model->paid_date = $value['paid_date'];
            $paid_model->paid_by = $value['paid_by'];
            $paid_model->receipt_no = $value['receipt_no'];
            $paid_model->save();
        }
        foreach ($data['overdue_fine'] as $value) {
            $overdue_model = new OverdueFine();
            $overdue_model->patent_id = $patent_id;
            $overdue_model->due_date = $value['due_date'];
            $overdue_model->original_amount = $value['original_amount'];
            $overdue_model->fine_amount = $value['fine_amount'];
            $overdue_model->total_amount = $value['total_amount'];
            $overdue_model->save();
        }
    }

    /**
     * 爬取基本信息
     *
     * @param array $application_no
     */
    public function crawlBasicInfo(array $application_no)
    {
        $base_uri = 'http://cpquery.sipo.gov.cn/txnQueryBibliographicData.do';
        $concurrency = count($application_no);
        $client = new Client([
            'headers' => [
                'User-Agent' => $this->getUA(),
            ],
            'proxy' => $this->getIP(),
            'cookies' => true,
            'timeout' => 60,
            'allow_redirects' => false,
            'connect_timeout' => 60,
        ]);
        $requests = function ($total) use ($base_uri, $application_no, $client) {
            foreach ($application_no as $patent) {
                yield function() use ($patent, $base_uri, $client) {
                    return $client->getAsync($base_uri . '?select-key:shenqingh=' . $patent['application_no']);
                };
            }
        };
        $patent_list = array_values($application_no);
        $pool = new Pool($client, $requests($concurrency), [
            'concurrency' => $concurrency,
            'fulfilled' => function (Response $response, $index) use ($patent_list) {
                if ($response->getStatusCode() == 200) {
                    $html = $response->getBody()->getContents();
                    if ($html == '') {
                        $this->stdout($patent_list[$index]['application_no'] . ' is null' .PHP_EOL);
                    } else {
                        $result = $this->parseBasicInfo($html);
                        $this->saveBasicInfo($result, $patent_list[$index]['application_no']);
                        $this->stdout($patent_list[$index]['application_no'] . ' OK'.PHP_EOL);
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($patent_list) {

                $this->stdout('Error occurred time:' . date('H:i:s',time()) . PHP_EOL);
                $this->stdout('Error No:' . $patent_list[$index]['application_no'] . ' Reason:' . $reason . PHP_EOL);
                // this is delivered each failed request
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();
    }

    /**
     * 解析专利信息页面
     *
     * @param $html
     * @return array
     */
    public function parseBasicInfo($html)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $last_span = $crawler->filter('body > span')->last();
        $result = [
            'filing_date' => null,
            'title' => null,
            'case_status' => null,
            'inventors' => null,
            'applicants' => null,
            'ip_agency' => null,
            'first_named_attorney' => null,
            'change_of_bibliographic_data' => null
        ];
        if (!$last_span->count()) {
            $this->stdout('Error: empty node'.PHP_EOL.'Source code: '.$html);
            return $result;
        } else {
            $key = $last_span->attr('id');
        }
        $useful_id = array_flip($this->decrypt($key));

        // 获取申请日
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $application_date_info = $crawler_info->filter('#zlxid span[name="record_zlx:shenqingr"] span')->each(
                function (Crawler $node) use ($useful_id){
                    if (isset($useful_id[$node->attr('id')])){
                        return $node->text();
                    }
                }
            );
        $result['filing_date'] = implode('', $application_date_info);

        // 获取名称
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $title_info = $crawler_info->filter('#zlxid span[name="record_zlx:zhuanlimc"] span')->each(
            function (Crawler $node) use ($useful_id){
                if (isset($useful_id[$node->attr('id')])){
                    return $node->text();
                }
            }
        );
        $result['title'] = implode('', $title_info);

        // 获取状态
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $status_info = $crawler_info->filter('#zlxid span[name="record_zlx:anjianywzt"] span')->each(
            function (Crawler $node) use ($useful_id){
                if (isset($useful_id[$node->attr('id')])){
                    return $node->text();
                }
            }
        );
        $result['case_status'] = implode('', $status_info);

        // 获取发明人
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $inventors_info = $crawler_info->filter('#fmrid span[name="record_fmr:famingrxm"] span')->each(
            function (Crawler $node) use ($useful_id){
                if (isset($useful_id[$node->attr('id')])){
                    return $node->text();
                }
            }
        );
        $result['inventors'] = implode('', $inventors_info);

        // 获取申请人
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $tr_html = $crawler_info->filter("#sqrid")->filter("tr")->each(
            function (Crawler $node) {
                return $node->html();
            }
        );
        $applicants = [];
        foreach ($tr_html as $idx => $tr) {
            if ($idx !== 0) {
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $applicant = $trCrawler->filter('span[name="record_sqr:shenqingrxm"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr('id')])) {
                            return $node->text();
                        }
                    }
                );
                $applicants[] = implode('', $applicant);
            }
        }
        $result['applicants'] = implode('，', $applicants);

        // 获取代理机构
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $ip_agency_info = $crawler_info->filter('#zldlid span[name="record_zldl:dailijgmc"] span')->each(
            function (Crawler $node) use ($useful_id){
                if (isset($useful_id[$node->attr('id')])){
                    return $node->text();
                }
            }
        );
        $result['ip_agency'] = implode('', $ip_agency_info);

        // 获取第一代理人
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $first_name_info = $crawler_info->filter('#zldlid span[name="record_zldl:diyidlrxm"] span')->each(
            function (Crawler $node) use ($useful_id){
                if (isset($useful_id[$node->attr('id')])){
                    return $node->text();
                }
            }
        );
        $result['first_named_attorney'] = implode('', $first_name_info);

        // 获取项目变更  返回结果是二维数组
        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $tr_html = $crawler_info->filter("#bgid")->filter("tr")->each(
            function (Crawler $node) {
                return $node->html();
            }
        );
        $change_of_bibliographic = [];
        foreach ($tr_html as $idx => $tr) {
            if ($idx !== 0) {
                $change = [];
                // 变更事项
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $items = $trCrawler->filter('span[name="record_zlxbg:biangengsx"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr('id')])) {
                            return $node->text();
                        }
                    }
                );
                $change['changed_item'] = implode('', $items);

                // 变更前
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $before = $trCrawler->filter('span[name="record_zlxbg:biangengqnr"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr('id')])) {
                            return $node->text();
                        }
                    }
                );
                $change['before_change'] = implode('', $before);

                // 变更后
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $after = $trCrawler->filter('span[name="record_zlxbg:biangenghnr"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr('id')])) {
                            return $node->text();
                        }
                    }
                );
                $change['after_change'] = implode('', $after);

                // 变更日期
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $date_of_change = $trCrawler->filter('span[name="record_zlxbg:biangengrq"]')->text();
                $change['date'] = $date_of_change;

                $change_of_bibliographic[] = $change;
            }
        }
        $result['change_of_bibliographic_data'] = $change_of_bibliographic;

        return $result;
    }

    /**
     * 保存基本信息
     *
     * @param $data
     * @param $application_no
     */
    public function saveBasicInfo($data, $application_no)
    {
        $model = Patent::findOne(['application_no' => $application_no]);
        if (strlen($application_no) == 13) {
            $patent_type = substr($application_no,4,1);
        } else {
            $patent_type = substr($application_no,2,1);
        }
        $model->patent_type = $patent_type == 1 ? '发明专利' : ($patent_type == 2 ? '实用新型' : ($patent_type == 3 ? '外观设计' : null));
        $model->title = $data['title'];
        $model->filing_date = $data['filing_date'];
        $model->case_status = $data['case_status'];
        $model->applicants = $data['applicants'];
        $model->inventors = $data['inventors'];
        $model->ip_agency = $data['ip_agency'];
        $model->first_named_attorney = $data['first_named_attorney'];
        $model->save();

        if ($data['change_of_bibliographic_data']) {
            foreach ($data['change_of_bibliographic_data'] as $value) {
                $change = new ChangeOfBibliographicData();
                $change->patent_id = $model->id;
                $change->date = $value['date'];
                $change->changed_item = $value['changed_item'];
                $change->before_change = $value['before_change'];
                $change->after_change = $value['after_change'];
                $change->save();
            }
        }
    }

    /**
     * 爬取公告信息
     *
     * @param array $application_no
     */
    public function crawlPublicationInfo(array $application_no)
    {
        $base_uri = 'http://cpquery.sipo.gov.cn/txnQueryPublicationData.do';
        $concurrency = count($application_no);
        $client = new Client([
            'headers' => [
                'User-Agent' => $this->getUA(),
            ],
            'proxy' => $this->getIP(),
            'cookies' => true,
            'timeout' => 60,
            'allow_redirects' => false,
            'connect_timeout' => 60,
        ]);
        $requests = function ($total) use ($base_uri, $application_no, $client) {
            foreach ($application_no as $patent) {
                yield function() use ($patent, $base_uri, $client) {
                    return $client->getAsync($base_uri . '?select-key:shenqingh=' . $patent['application_no']);
                };
            }
        };
        $patent_list = array_values($application_no);
        $pool = new Pool($client, $requests($concurrency), [
            'concurrency' => $concurrency,
            'fulfilled' => function (Response $response, $index) use ($patent_list) {
                if ($response->getStatusCode() == 200) {
                    $html = $response->getBody()->getContents();
                    if ($html === '') {
                        $this->stdout($patent_list[$index]['application_no'] . ' is null' .PHP_EOL);
                    } else {
                        $result = $this->parsePublicationInfo($html);
                        $this->savePublicationInfo($result, $patent_list[$index]['application_no']);
                        $this->stdout($patent_list[$index]['application_no'] . ' OK'.PHP_EOL);
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($patent_list) {

                $this->stdout('Error occurred time:' . date('H:i:s',time()) . PHP_EOL);
                $this->stdout('Error No:' . $patent_list[$index]['application_no'] . ' Reason:' . $reason . PHP_EOL);
                // this is delivered each failed request
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();
    }

    /**
     * 解析公布公告页面
     *
     * @param $html
     * @return array
     */
    public function parsePublicationInfo($html)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $last_span = $crawler->filter('body > span')->last();
        // 发明公布/授权公告
        $publication = [
            'publication_date' => null,
            'publication_no' => null,
            'issue_announcement' => null
        ];
        if (!$last_span->count()) {
            $this->stdout('Error: empty node'.PHP_EOL.'Source code: '.$html);
            return $publication;
        } else {
            $key = $last_span->attr('id');
        }
        $useful_id = array_flip($this->decrypt($key));

        $crawler_info = new Crawler();
        $crawler_info->addHtmlContent($html);
        $tr_html = $crawler_info->filter("#gkggid")->filter("tr")->each(
            function (Crawler $node) {
                return $node->html();
            }
        );
        foreach ($tr_html as $idx => $tr) {
            if ($idx !== 0) {
                $trCrawler = new Crawler();
                $trCrawler->addHtmlContent($tr);
                $type = $trCrawler->filter('span[name="record_gkgg:gongkaigglx"] span')->each(
                    function (Crawler $node) use ($useful_id) {
                        if (isset($useful_id[$node->attr('id')])) {
                            return $node->text();
                        }
                    }
                );
                $type = implode('', $type);
                if (mb_substr($type, 2) == '授权公告') {
                    // 专利类型（不用来存入数据库）
                    $patent_type = mb_substr($type, 0, 2);
                    // 授权公告日
                    $issue_announcement = $trCrawler->filter('span[name="record_gkgg:gonggaor"] span')->each(
                        function (Crawler $node) use ($useful_id) {
                            if (isset($useful_id[$node->attr('id')])) {
                                return $node->text();
                            }
                        }
                    );
                    $publication['issue_announcement'] = implode('', $issue_announcement);
                    // 授权公告号,授权公告号没有存入数据库
                    $publication_no = $trCrawler->filter('span[name="record_gkgg:gonggaoh"] span')->each(
                        function (Crawler $node) use ($useful_id) {
                            if (isset($useful_id[$node->attr('id')])) {
                                return $node->text();
                            }
                        }
                    );
                    $publication_no = str_replace(' ','',implode('', $publication_no));

                    // 如果是新型和外观的话，公开号和授权号是一样的,公开日和授权日也是一样的
                    if ($patent_type != '发明') {
                        $publication['publication_date'] = $publication['issue_announcement'];
                        $publication['publication_no'] = $publication_no;
                    }
                } elseif($type == '发明公布') {
                    // 公开号
                    $trCrawler = new Crawler();
                    $trCrawler->addHtmlContent($tr);
                    $publication_no = $trCrawler->filter('span[name="record_gkgg:gonggaoh"] span')->each(
                        function (Crawler $node) use ($useful_id) {
                            if (isset($useful_id[$node->attr('id')])) {
                                return $node->text();
                            }
                        }
                    );
                    $publication['publication_no'] = str_replace(' ', '',implode('', $publication_no));
                    // 公开日
                    $trCrawler = new Crawler();
                    $trCrawler->addHtmlContent($tr);
                    $publication_date = $trCrawler->filter('span[name="record_gkgg:gonggaor"] span')->each(
                        function (Crawler $node) use ($useful_id) {
                            if (isset($useful_id[$node->attr('id')])) {
                                return $node->text();
                            }
                        }
                    );
                    $publication['publication_date'] = implode('', $publication_date);
                }
            }
        }

        return $publication;
    }

    /**
     * 保存公告信息
     *
     * @param $data
     * @param $application_no
     */
    public function savePublicationInfo($data, $application_no)
    {
        $model = Patent::findOne(['application_no' => $application_no]);
        $model->publication_no = $data['publication_no'];
        $model->publication_date = $data['publication_date'];
        $model->issue_announcement = $data['issue_announcement'];
        $model->save();
    }
    
    /**
     * 将所有的申请号放入队列
     * 暂时没必要使用队列
     */
    public function queue()
    {
        $redis = Yii::$app->redis;
        $redis->del('patent_list');
        $patents_list = Patent::find()->select(['application_no'])->asArray()->all();
        foreach ($patents_list as $patent) {
            $redis->rpush('patent_list',$patent['patentApplicationNo']);
        }
    }

    /**
     * 获取随机User-Agent
     * @return string
     */
    public function getUA()
    {
        $ua = [
            'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:23.0) Gecko/20100101 Firefox/23.0',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.62 Safari/537.36',
            'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; WOW64; Trident/6.0)',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.146 Safari/537.36',
            'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/33.0.1750.146 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64; rv:24.0) Gecko/20140205 Firefox/24.0 Iceweasel/24.3.0',
            'Mozilla/5.0 (Windows NT 6.2; WOW64; rv:28.0) Gecko/20100101 Firefox/28.0',
            'Mozilla/5.0 (Windows NT 6.2; WOW64; rv:28.0) AppleWebKit/534.57.2 (KHTML, like Gecko) Version/5.1.7 Safari/534.57.2',
            "Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; AcooBrowser; .NET CLR 1.1.4322; .NET CLR 2.0.50727)",
            "Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.0; Acoo Browser; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; .NET CLR 3.0.04506)",
            "Mozilla/4.0 (compatible; MSIE 7.0; AOL 9.5; AOLBuild 4337.35; Windows NT 5.1; .NET CLR 1.1.4322; .NET CLR 2.0.50727)",
            "Mozilla/5.0 (Windows; U; MSIE 9.0; Windows NT 9.0; en-US)",
            "Mozilla/4.0 (compatible; MSIE 7.0b; Windows NT 5.2; .NET CLR 1.1.4322; .NET CLR 2.0.50727; InfoPath.2; .NET CLR 3.0.04506.30)",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; zh-CN) AppleWebKit/523.15 (KHTML, like Gecko, Safari/419.3) Arora/0.3 (Change: 287 c9dfb30)",
            "Mozilla/5.0 (X11; U; Linux; en-US) AppleWebKit/527+ (KHTML, like Gecko, Safari/419.3) Arora/0.6",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.2pre) Gecko/20070215 K-Ninja/2.1.1",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; zh-CN; rv:1.9) Gecko/20080705 Firefox/3.0 Kapiko/3.0",
            "Mozilla/5.0 (X11; Linux i686; U;) Gecko/20070322 Kazehakase/0.4.5",
            "Mozilla/5.0 (X11; U; Linux i686; en-US; rv:1.9.0.8) Gecko Fedora/1.9.0.8-1.fc10 Kazehakase/0.5.6",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.11 (KHTML, like Gecko) Chrome/17.0.963.56 Safari/535.11",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_3) AppleWebKit/535.20 (KHTML, like Gecko) Chrome/19.0.1036.7 Safari/535.20",
            "Opera/9.80 (Macintosh; Intel Mac OS X 10.6.8; U; fr) Presto/2.9.168 Version/11.52",
            "Mozilla/5.0 (Linux; U; Android 2.3.6; en-us; Nexus S Build/GRK39F) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Avant Browser/1.2.789rel1 (http://www.avantbrowser.com)",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/532.5 (KHTML, like Gecko) Chrome/4.0.249.0 Safari/532.5",
            "Mozilla/5.0 (Windows; U; Windows NT 5.2; en-US) AppleWebKit/532.9 (KHTML, like Gecko) Chrome/5.0.310.0 Safari/532.9",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US) AppleWebKit/534.7 (KHTML, like Gecko) Chrome/7.0.514.0 Safari/534.7",
            "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US) AppleWebKit/534.14 (KHTML, like Gecko) Chrome/9.0.601.0 Safari/534.14",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.14 (KHTML, like Gecko) Chrome/10.0.601.0 Safari/534.14",
            "Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.20 (KHTML, like Gecko) Chrome/11.0.672.2 Safari/534.20",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/534.27 (KHTML, like Gecko) Chrome/12.0.712.0 Safari/534.27",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.1 (KHTML, like Gecko) Chrome/13.0.782.24 Safari/535.1",
            "Mozilla/5.0 (Windows NT 6.0) AppleWebKit/535.2 (KHTML, like Gecko) Chrome/15.0.874.120 Safari/535.2",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.7 (KHTML, like Gecko) Chrome/16.0.912.36 Safari/535.7",
            "Mozilla/5.0 (Windows; U; Windows NT 6.0 x64; en-US; rv:1.9pre) Gecko/2008072421 Minefield/3.0.2pre",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.0.10) Gecko/2009042316 Firefox/3.0.10",
            "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-GB; rv:1.9.0.11) Gecko/2009060215 Firefox/3.0.11 (.NET CLR 3.5.30729)",
            "Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.1.6) Gecko/20091201 Firefox/3.5.6 GTB5",
            "Mozilla/5.0 (Windows; U; Windows NT 5.1; tr; rv:1.9.2.8) Gecko/20100722 Firefox/3.6.8 ( .NET CLR 3.5.30729; .NET4.0E)",
            "Mozilla/5.0 (Windows NT 6.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:2.0.1) Gecko/20100101 Firefox/4.0.1",
            "Mozilla/5.0 (Windows NT 5.1; rv:5.0) Gecko/20100101 Firefox/5.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:6.0a2) Gecko/20110622 Firefox/6.0a2",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:7.0.1) Gecko/20100101 Firefox/7.0.1",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:2.0b4pre) Gecko/20100815 Minefield/4.0b4pre",
            "Mozilla/4.0 (compatible; MSIE 5.5; Windows NT 5.0 )",
            "Mozilla/4.0 (compatible; MSIE 5.5; Windows 98; Win 9x 4.90)",
            "Mozilla/5.0 (Windows; U; Windows XP) Gecko MultiZilla/1.6.1.0a",
            "Mozilla/2.02E (Win95; U)",
            "Mozilla/3.01Gold (Win95; I)",
            "Mozilla/4.8 [en] (Windows NT 5.1; U)",
            "Mozilla/5.0 (Windows; U; Win98; en-US; rv:1.4) Gecko Netscape/7.1 (ax)",
            "HTC_Dream Mozilla/5.0 (Linux; U; Android 1.5; en-ca; Build/CUPCAKE) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (hp-tablet; Linux; hpwOS/3.0.2; U; de-DE) AppleWebKit/534.6 (KHTML, like Gecko) wOSBrowser/234.40.1 Safari/534.6 TouchPad/1.0",
            "Mozilla/5.0 (Linux; U; Android 1.5; en-us; sdk Build/CUPCAKE) AppleWebkit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 2.1; en-us; Nexus One Build/ERD62) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; Nexus One Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 1.5; en-us; htc_bahamas Build/CRB17) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 2.1-update1; de-de; HTC Desire 1.19.161.5 Build/ERE27) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; Sprint APA9292KT Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 1.5; de-ch; HTC Hero Build/CUPCAKE) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; ADR6300 Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 2.1; en-us; HTC Legend Build/cupcake) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 1.5; de-de; HTC Magic Build/PLAT-RC33) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1 FirePHP/0.3",
            "Mozilla/5.0 (Linux; U; Android 1.6; en-us; HTC_TATTOO_A3288 Build/DRC79) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 1.0; en-us; dream) AppleWebKit/525.10  (KHTML, like Gecko) Version/3.0.4 Mobile Safari/523.12.2",
            "Mozilla/5.0 (Linux; U; Android 1.5; en-us; T-Mobile G1 Build/CRB43) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari 525.20.1",
            "Mozilla/5.0 (Linux; U; Android 1.5; en-gb; T-Mobile_G2_Touch Build/CUPCAKE) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 2.0; en-us; Droid Build/ESD20) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; Droid Build/FRG22D) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 2.0; en-us; Milestone Build/ SHOLS_U2_01.03.1) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.0.1; de-de; Milestone Build/SHOLS_U2_01.14.0) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 3.0; en-us; Xoom Build/HRI39) AppleWebKit/525.10  (KHTML, like Gecko) Version/3.0.4 Mobile Safari/523.12.2",
            "Mozilla/5.0 (Linux; U; Android 0.5; en-us) AppleWebKit/522  (KHTML, like Gecko) Safari/419.3",
            "Mozilla/5.0 (Linux; U; Android 1.1; en-gb; dream) AppleWebKit/525.10  (KHTML, like Gecko) Version/3.0.4 Mobile Safari/523.12.2",
            "Mozilla/5.0 (Linux; U; Android 2.0; en-us; Droid Build/ESD20) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.1; en-us; Nexus One Build/ERD62) AppleWebKit/530.17 (KHTML, like Gecko) Version/4.0 Mobile Safari/530.17",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; Sprint APA9292KT Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-us; ADR6300 Build/FRF91) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 2.2; en-ca; GT-P1000M Build/FROYO) AppleWebKit/533.1 (KHTML, like Gecko) Version/4.0 Mobile Safari/533.1",
            "Mozilla/5.0 (Linux; U; Android 3.0.1; fr-fr; A500 Build/HRI66) AppleWebKit/534.13 (KHTML, like Gecko) Version/4.0 Safari/534.13",
            "Mozilla/5.0 (Linux; U; Android 3.0; en-us; Xoom Build/HRI39) AppleWebKit/525.10  (KHTML, like Gecko) Version/3.0.4 Mobile Safari/523.12.2",
            "Mozilla/5.0 (Linux; U; Android 1.6; es-es; SonyEricssonX10i Build/R1FA016) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
            "Mozilla/5.0 (Linux; U; Android 1.6; en-us; SonyEricssonX10i Build/R1AA056) AppleWebKit/528.5  (KHTML, like Gecko) Version/3.1.2 Mobile Safari/525.20.1",
        ];
        return $ua[mt_rand(0, count($ua) - 1)];
    }

    /**
     * 获取随机IP
     * @return string
     */
    public function getIP()
    {
        // 代理服务器
        $proxyServer = Yii::$app->params['proxy']['server'];

        // 隧道身份信息
        $proxyUser   = Yii::$app->params['proxy']['username'];
        $proxyPass   = Yii::$app->params['proxy']['password'];

        return 'http://' . $proxyUser . ':' . $proxyPass . '@' . $proxyServer;
    }

    /**
     * js解密
     * @param $key
     * @return array
     */
    public function decrypt($key)
    {
        $b2 = '';
        $b4 = 0;
        for ($b3 = 0; $b3 < strlen($key); $b3 += 2) {
            if ($b4 > 255) {
                $b4 = 0;
            }
            $b1 = (int)(hexdec(substr($key, $b3, 2)) ^ $b4++);
            $b2 .= chr($b1);
        }
        if ($b2) {
            return array_filter(explode(',', $b2));
        } else {
            return [];
        }
    }

    /**
     * 测试使用
     */
    public function actionTest()
    {
        // 基本信息测试
//        $result = $this->parseBasicInfo($this->basic_info_html);
//        print_r($result);

        // 公开号测试 publication_info_html为发明专利,publication_info_html_b为实用新型
//        $result = $this->parsePublicationInfo($this->publication_info_html);
//        print_r($result);

        // 费用信息测试
//        $result = $this->parsePaymentInfo($this->fees_info_html);
//        print_r($result);

//        print_r(implode(',',$applicants));
//        $result[''] = implode('', $_info);

//        echo $this->getIP();
    }

    public $basic_info_html = <<<HTML
<html>
<head>
<title>中国及多国专利审查信息查询</title>
</head>
<body>

<!--导航-->
<input id="usertype" type="hidden"
			value="1">
<div class="hd">
	<div class="head" id="header1">
		<div class="logo_box">
			
		</div>
		<div class="nav_box">
			<ul class="header_menu">
				<li id="header_query" 
					class="_over" ><div
						 class="nav_over" >
						中国专利审查信息查询
					</div></li>
				<li id="header-family" ><div
						>
						多国发明专利审查信息查询
					</div></li>
			</ul>

		</div>
		<div class="hr">
			<ul>
				<!-- 公众用户 -->
				
					<li id="regpublic"><a href="javascript:;">注册</a></li>
					<li id="loginpublic"><a href="javascript:;">登录</a></li>
				
				<!-- 公众注册用户 -->
				
				<!-- 电子申请注册用户 -->
				
				<!-- 公用部分  -->
				
				<li title="选择语言"> 
					<div class="selectlang">
						<a href="javascript:;"> <i class="lang"></i>
						</a>
						<div class="topmenulist hidden">
							<ul>
								<li id="zh"><span  title="中文">中文</span></li>
								<li id="en"><span  title="English">English</span></li>
								<li id="de"><span  title="Deutsch">Deutsch</span></li>
								<li id="es"><span  title="Espa&ntilde;ol">Espa&ntilde;ol</span></li>
								<li id="fr"><span  title="Fran&ccedil;ais">Fran&ccedil;ais</span></li>
								<li id="ja"><span  title="&#26085;&#26412;&#35486;">&#26085;&#26412;&#35486;</span></li>
								<li id="ko"><span  title="&#54620;&#44397;&#50612;">&#54620;&#44397;&#50612;</span></li>
								<li id="ru"><span  title="&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;">&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;</span></li>
							</ul>
						</div>
					</div>
				</li>
				<li id="navLogoutBtn" class="mouse_cross" title="退出">
					<a href="javascript:;"><i class="out"></i></a>
			 	</li>
			</ul>
		</div>

		<ul class="float_botton">
		<li id="backToTopBtn" title="返回顶部" style="display: none;"><i
				class="top"></i></li>
			<li id="backToPage" class="hidden"><a href="javascript:;"><i
					class="back" title="返回"></i></a></li>
			<li id="faqBtn" ><a href="javascript:;"><i
					class="faq_icon" title="FAQ"></i></a></li>
		</ul>
	</div>
</div>
<!-- header.jsp对应js -->

	<input type='hidden' name='select-key:backPage' id='select-key:backPage' value="http://cpquery.sipo.gov.cn/txnQueryOrdinaryPatents.do?select-key:shenqingh=&amp;select-key:zhuanlimc=&amp;select-key:shenqingrxm=%E5%AE%89%E5%BE%BD%E7%80%9A%E6%B5%B7%E5%8D%9A%E5%85%B4%E7%94%9F%E7%89%A9%E6%8A%80%E6%9C%AF%E6%9C%89%E9%99%90%E5%85%AC%E5%8F%B8&amp;select-key:zhuanlilx=&amp;select-key:shenqingr_from=&amp;select-key:shenqingr_to=&amp;verycode=10&amp;inner-flag:open-type=window&amp;inner-flag:flowno=1508667783030">
	<input type='hidden' name='show:iszlxshow' id='show:iszlxshow' value="yes">
	<input type='hidden' name='show:issqrshow' id='show:issqrshow' value="yes">
	<input type='hidden' name='show:isfmrshow' id='show:isfmrshow' value="yes">
	<input type='hidden' name='show:islxrshow' id='show:islxrshow' value="no">
	<input type='hidden' name='show:iszldlshow' id='show:iszldlshow' value="yes">
	<input type='hidden' name='show:isyxqshow' id='show:isyxqshow' value="no">
	<input type='hidden' name='show:ispctshow' id='show:ispctshow' value="no">
	<input type='hidden' name='show:isbgshow' id='show:isbgshow' value="yes">
	<input type='hidden' name='record_zlx:zhuanlilx' id='record_zlx:zhuanlilx' value="1">
	<div class="bd">
		<div class="tab_body">
			<div class="tab_list">
				<ul>
				    
				    <li id="jbxx" class="tab_first on"><div class="tab_top_on"></div>
						<p>
							申请信息
						</p></li>
					<li id='wjxx'><div class="tab_top"></div>
						<p>
							审查信息
						</p></li>
					<li id='fyxx'><div class="tab_top"></div>
						<p>
							费用信息
						</p></li>
					<li id='fwxx'><div class="tab_top"></div>
						<p>
							发文信息
						</p></li>
					<li id='gbgg'><div class="tab_top"></div>
						<p>
							公布公告
						</p></li>
						
					<li id='djbxx'><div class="tab_top"></div>
						<p>专利登记簿</p></li>
						
					<li id='tzzlxx'><div class="tab_top"></div>
						<p>
							同族案件信息
						</p></li>
					
				    
					
				</ul>
			</div>
			<div class="tab_box">
				<div class="imfor_part1">
					<h2>
						著录项目信息
						<i id="zlxtitle" class="draw_up"></i>
					</h2>
					<div id="zlxid">
						<div class="imfor_box">
							<table class="imfor_table_grid">
								<tr>
									<td width="40%" class="td1">申请号/专利号：</td>
									<td width="60%"><span name="record_zlx:shenqingh" title="2010102563366">2010102563366</span></td>
								</tr>
								<tr>
									<td class="td1">申请日：</td>
									<td><span name="record_zlx:shenqingr" title="pos||"><span id="382619bfd880485c9e8ecdafd3015ad7" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="a0037cf79e024bb28e6391b9b263f392" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="64f74b18609e4befa21e39617505c965" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="6e2d2db0779f4335986cf47cbfd2eba1" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="a64cbc5bd4ae46e6a349bb3a3e2c2f0f" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="96a55bc3e8c7472eb3b67642497d44fe" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="0f8eaba0d88745a1a42713dc5fd237c8" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="45c40cd2ab824d1aabd6245681cb2a9a" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="f76ae47470c440fe9456d736f412f57d" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span><span id="3cd57a67a8a9416ab3f9e15e00c71277" class="nlkfqirnlfjerldfgzxcyiuro">2010-08-18</span></span></td>
								</tr>
								<tr>
									<td class="td1">案件状态：</td>
									<td><span name="record_zlx:anjianywzt" title="pos||"><span id="a8f041a565004d8bb377b5aaa392af1e" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="7b8d0c7808874b38b67c218e3e42c219" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="6214d2842adf4602b20f10f0b1bc4d48" class="nlkfqirnlfjerldfgzxcyiuro">权维持</span><span id="63bc474a0b434bee87d15847ca5acc29" class="nlkfqirnlfjerldfgzxcyiuro">权维持</span><span id="a291a89f5a834728a32e6b1f9a4fcad0" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="bf1ca866e0ba46cfb30f770125d33a67" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="5fb389e6027c4d669e23a1b85bf8fdac" class="nlkfqirnlfjerldfgzxcyiuro">权维持</span><span id="86004dfd68a645a498c4065d69036a1b" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="3afa9394c967456eb399810c50f4dad5" class="nlkfqirnlfjerldfgzxcyiuro">权维持</span><span id="ddb4f507879445779ce517fd22c5cf2c" class="nlkfqirnlfjerldfgzxcyiuro">专利</span></span></td>
								</tr>
							</table>
						</div>
						<div class="imfor_box2">
							<table class="imfor_table_grid">
								<tr>
									<td width="40%" class="td1">发明名称：</td>
									<td width="60%"><span name="record_zlx:zhuanlimc" title="pos||"><span id="edfa29edbb624699bbd42785e7c71aa4" class="nlkfqirnlfjerldfgzxcyiuro">种</span><span id="2ad39fc42ef84835bdee8b898079c659" class="nlkfqirnlfjerldfgzxcyiuro">种</span><span id="e172958922824a59b2886634a3c4030c" class="nlkfqirnlfjerldfgzxcyiuro">一</span><span id="a195ac0f13f04d67bc3517c7852987fa" class="nlkfqirnlfjerldfgzxcyiuro">种</span><span id="f653b3548bbb4b6a8c68aef6d56aa824" class="nlkfqirnlfjerldfgzxcyiuro">物</span><span id="8e0a906f11c74fe9be971aca4dc74f54" class="nlkfqirnlfjerldfgzxcyiuro">微</span><span id="85733de899434306ab75462acfe757d3" class="nlkfqirnlfjerldfgzxcyiuro">生</span><span id="0446f0e1c1b54947982c129d75841202" class="nlkfqirnlfjerldfgzxcyiuro">物</span><span id="ec453cf96f1c43af9e72408a8e14f120" class="nlkfqirnlfjerldfgzxcyiuro">燃</span><span id="d5d0a0956c584ed49effc43219911491" class="nlkfqirnlfjerldfgzxcyiuro">料电池及其应用</span></span></td>
								</tr>
								<tr>
									<td width="40%" class="td1">主分类号 ：</td>
									<td width="60%"><span name="record_zlx:zhufenlh" title="pos||"><span id="aa0022eef3574752ae56e54f3bc54515" class="nlkfqirnlfjerldfgzxcyiuro"> </span><span id="fa6224d20eff49e796fa56acf2151fff" class="nlkfqirnlfjerldfgzxcyiuro">H</span><span id="992a765f72484240bf9ef58e0c199930" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="631abbc894684f8485bd4dc567bf3661" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="a1d56b1d8489403085ba857ada33aff7" class="nlkfqirnlfjerldfgzxcyiuro">M</span><span id="0ad39dd426654c868bceb29efbc9f1e4" class="nlkfqirnlfjerldfgzxcyiuro">H</span><span id="0f1ac8b8298a41c5938de578f2091e27" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="a29b89bfade047a7a2f35d8b27e9d267" class="nlkfqirnlfjerldfgzxcyiuro"> </span><span id="1c571a7eaa20451990c64e38888a54eb" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="76c1c03a5df043e991e78d4ef8686267" class="nlkfqirnlfjerldfgzxcyiuro">/06</span></span></td>
								</tr>
								<tr>
									<td class="td1">分案提交日：</td>
									<td><span name="record_zlx:fenantjr" title="pos||"></span></td>
								</tr>
							</table>
						</div>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						申请人
						<i id="sqrtitle" class="draw_up"></i>
					</h2>
					<div id="sqrid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="20%">姓名或名称</th>
								<th width="30%">国籍或总部所在地</th>
								<th width="10%">邮政编码</th>
								<th width="40%">详细地址</th>
							</tr>
							
								<tr>
									<td width="30%"><span name="record_sqr:shenqingrxm" title="pos||"><span id="adfab3f36ce6478faca65bf3972ab851" class="nlkfqirnlfjerldfgzxcyiuro">物技术有限公司</span><span id="6d84b8324559461da1cd110818d1ca14" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海博兴生</span><span id="908bdb96040f47a9831db43355dbd5b2" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海博兴生</span><span id="a6ef42d7d69142389b9a1a432ef6051c" class="nlkfqirnlfjerldfgzxcyiuro">物技术有限公司</span><span id="347a5c3ac0f54bc1b5749ff904b85917" class="nlkfqirnlfjerldfgzxcyiuro">物技术有限公司</span><span id="1e40ac9e8c1f4a5a8362c03aa65e71db" class="nlkfqirnlfjerldfgzxcyiuro">物技术有限公司</span><span id="4382f3c0db1f483e8ce50f057ae14d4c" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海博兴生</span><span id="6d3c028a02a24922a35ca169bfc18670" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海博兴生</span><span id="b7b602bf66284f3dbf5e10662b58d6be" class="nlkfqirnlfjerldfgzxcyiuro">物技术有限公司</span><span id="622b0a1f156640d0b9d6e72b51420f3b" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海博兴生</span></span></td>
									<td width="20%"><span name="record_sqr:shenqingrgb" title="--">--</span></td>
									<td width="10%"><span name="record_sqr:shenqingryb" title="--">--</span></td>
									<td width="45%"><span name="record_sqr:shenqingrdz" title="--">--</span></td>
								</tr>
								<tr>
									<td width="30%"><span name="record_sqr:shenqingrxm" title="pos||"><span id="347a5c3ac0f54bc1b5749ff904b85917" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="f74cbdec84e6458da882fa7df0a90cc3" class="nlkfqirnlfjer1dfgzxcyiuro">福建省百林环保技术有限公司</span><span id="4ab2f8b319ac4b7e9b5cd795b44e3560" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="2deb7fa69ff149c486800da0d9cc2ee4" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="9d3ca82fb31c4900a5ba406c67988f30" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="9c55266f135f4bf2a80bf7a08bc610e2" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="0cef799bc4e44d249f152e56f9eaec41" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="001c68cba3f74bac8f77058b4af5e09b" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="ca9243c290d94d24bc4b6cd4125586f1" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span><span id="77603d23d90243748fbda8d253cac8ad" class="nlkfqirnlfjerldfgzxcyiuro">福建省百林环保技术有限公司</span></span></td>
									<td width="20%"><span name="record_sqr:shenqingrgb" title="--">--</span></td>
									<td width="10%"><span name="record_sqr:shenqingryb" title="--">--</span></td>
									<td width="45%"><span name="record_sqr:shenqingrdz" title="--">--</span></td>
								</tr>
							
						</table>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						发明人/设计人
						<i id="fmrtitle" class="draw_up"></i>
					</h2>
					<div id="fmrid" class="imfor_box">
						<table class="imfor_table_grid">
							<tr>
								<td width="30%" class="td1">发明人姓名：</td>
								<td width="70%"><span name="record_fmr:famingrxm" title="pos||"><span id="2394fbe55d634ce8895082be3033a202" class="nlkfqirnlfjerldfgzxcyiuro">秀</span><span id="fb2c70c0276544c995ac7bea16f4281e" class="nlkfqirnlfjerldfgzxcyiuro">李</span><span id="a44d9733007f490cbd88307033f9e732" class="nlkfqirnlfjerldfgzxcyiuro">秀</span><span id="ee1fe23f906443d68f1935ad758145d6" class="nlkfqirnlfjerldfgzxcyiuro">芬</span><span id="cdffead208f146398b4d5c1269a7f20a" class="nlkfqirnlfjerldfgzxcyiuro">、</span><span id="cdfe481fb6a247d9ab19a476f6389bb9" class="nlkfqirnlfjerldfgzxcyiuro">王</span><span id="2fa0ba962e6e431d85a4999ad6aba198" class="nlkfqirnlfjerldfgzxcyiuro">王</span><span id="b3e808a5ebc042309c50cd9b790647c6" class="nlkfqirnlfjerldfgzxcyiuro">王</span><span id="7580e114ca3f4914bcff86304c893f42" class="nlkfqirnlfjerldfgzxcyiuro">、</span><span id="f798619bd144434fa2feb4f687ed21dd" class="nlkfqirnlfjerldfgzxcyiuro">新华</span></span></td>
							</tr>
						</table>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						联系人
						<i id="lxrtitle" class="draw_up"></i>
					</h2>
					<div id="lxrid">
						<div class="imfor_box">
							<table class="imfor_table_grid">
								<tr>
									<td width="30%" class="td1">姓名：</td>
									<td width="70%"><span name="record_lxr:lianxirxm" title="pos||"></span></td>
								</tr>
								<tr>
									<td width="30%" class="td1">邮政编码：</td>
									<td width="70%"><span name="record_lxr:lianxiryb" title="pos||"></span></td>
								</tr>

							</table>
						</div>
						<div class="imfor_box2">
							<table class="imfor_table_grid">
								<tr>
									<td width="30%" class="td1">详细地址：</td>
									<td width="70%"><span name="record_lxr:lianxirdz" title="pos||"></span></td>
								</tr>
							</table>
						</div>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						代理情况
						<i id="zldltitle" class="draw_up"></i>
					</h2>
					<div id="zldlid">
						<div class="imfor_box">
							<table class="imfor_table_grid">
								<tr>
									<td width="40%" class="td1">代理机构名称：</td>
									<td width="60%"><span name="record_zldl:dailijgmc" title="pos||"><span id="547752c9d1ad49acacfd93525b6736b9" class="nlkfqirnlfjerldfgzxcyiuro">识产</span><span id="f6c25c55c92946159781bf6b5a0158f0" class="nlkfqirnlfjerldfgzxcyiuro">北京</span><span id="6989476c7edd4b33b00d37421f5c9a2c" class="nlkfqirnlfjerldfgzxcyiuro">合知</span><span id="c2c2f30b63054fd6a8936e1f820c0d43" class="nlkfqirnlfjerldfgzxcyiuro">北京</span><span id="404b56c341614c02b2ff23075e28b0c0" class="nlkfqirnlfjerldfgzxcyiuro">权代</span><span id="36834ee067a94bedacdcd6d8fb466727" class="nlkfqirnlfjerldfgzxcyiuro">汇信</span><span id="8002e24cb6c7481c995b9eba0b888312" class="nlkfqirnlfjerldfgzxcyiuro">合知</span><span id="9f796cb859fb4cb8817b4259d102cf2a" class="nlkfqirnlfjerldfgzxcyiuro">识产</span><span id="2cde0870069b4861aefc3847dd10a467" class="nlkfqirnlfjerldfgzxcyiuro">权代</span><span id="da2ab6392a1547b8810e1377f6b1383e" class="nlkfqirnlfjerldfgzxcyiuro">理有限公司</span></span></td>
								</tr>
							</table>
						</div>
						<div class="imfor_box2">
							<table class="imfor_table_grid">
								<tr>
									<td width="40%" class="td1">第一代理人：</td>
									<td width="60%"><span name="record_zldl:diyidlrxm" title="pos||"><span id="6ddc2db0ff3d4c868c8a8b354e9331f2" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="1b3c88c6ca1c45298120350f389c8b7b" class="nlkfqirnlfjerldfgzxcyiuro">王</span><span id="369d6c949c6a4ef6af25a6559ea0e44f" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="e5f9a87597484ec1b7aebe3ec8491551" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="4eb75bb088d04cdc93aedd9464a49e5c" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="441865c1e58143c8b0fe4b6c773a47a1" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="9afd709a37cf4a959d3e0408a505ba0a" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="f52f193ebf844a72b165ae0d922e1a72" class="nlkfqirnlfjerldfgzxcyiuro">王</span><span id="fb415beb2c9447acabbb4bc026b4f1d4" class="nlkfqirnlfjerldfgzxcyiuro">秀丽</span><span id="d0bfcbc819404104a072e19e9d146c11" class="nlkfqirnlfjerldfgzxcyiuro">王</span></span></td>
								</tr>
							</table>
						</div>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						优先权
						<i id="yxqtitle" class="draw_up"></i>
					</h2>
					<div id="yxqid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th>在先申请号</th>
								<th>在先申请日</th>
								<th>原受理机构名称</th>
							</tr>
							
						</table>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						申请国际阶段
						<i id="pcttitle" class="draw_up"></i>
					</h2>
					<div id="pctid">
						<div class="imfor_box">
							<table class="imfor_table_grid">
								<tr>
									<td width="30%" class="td1">国际申请号：</td>
									<td width="70%"><span name="record_pct:guojisqh" title="pos||"></span></td>
								</tr>
								<tr>
									<td class="td1" width="30%">国际申请日：</td>
									<td width="70%"><span name="record_pct:guojisqr" title="pos||"></span></td>
								</tr>
							</table>
						</div>
						<div class="imfor_box2">
							<table class="imfor_table_grid">
								<tr>
									<td width="30%" class="td1">国际公布号：</td>
									<td width="70%"><span name="record_pct:guojigbh" title="pos||"></span></td>
								</tr>
								<tr>
									<td width="30%" class="td1">国际公布日：</td>
									<td width="70%"><span name="record_pct:guojigbr" title="pos||"></span></td>
								</tr>
							</table>
						</div>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						著录项目变更
						<i id="bgtitle" class="draw_up"></i>
					</h2>
					<div id="bgid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="15%">变更手续处理日</th>
								<th width="25%">变更事项</th>
								<th width="30%">变更前</th>
								<th width="30%">变更后</th>
							</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="811092ede5fc4dc1a68877752b972c9d" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="224925e6babd49e387874fcd5719d755" class="nlkfqirnlfjerldfgzxcyiuro">更】城市</span><span id="40382d7d549e410c8d57e3359a0b0143" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="4a69649a196e454e8c5ebe5fbdea732d" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="1e95fddce8e04a98bb7bcf763982ed77" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="9c906a7d3df04bbb863ca857e2eb67a7" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="acfa9194bd55498e9deb0c0e394b9cf5" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="0d5e00cd19c44e898191451c8f4b58ec" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="1f7f319f0c2042f3be40ee7b52a72ac2" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="8363575b136e43b095b9646765293819" class="nlkfqirnlfjerldfgzxcyiuro">更】城市</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="f026a681513a4af1b584379f1f79490f" class="nlkfqirnlfjerldfgzxcyiuro">320</span><span id="4cab8f9d934d4efcab8f184a1083b99e" class="nlkfqirnlfjerldfgzxcyiuro">320</span><span id="b22beb1b9ae244478475ce99b276793f" class="nlkfqirnlfjerldfgzxcyiuro">200</span><span id="70866110f8c74bf19c92aae40375fa3d" class="nlkfqirnlfjerldfgzxcyiuro">320</span><span id="3e9dd5729e8f42b99482bebbae1517cd" class="nlkfqirnlfjerldfgzxcyiuro">200</span><span id="377cf4f8b3ee48a29cb3147f2df280ab" class="nlkfqirnlfjerldfgzxcyiuro">200</span><span id="d91d70874f87415ba68989efa32627bc" class="nlkfqirnlfjerldfgzxcyiuro">200</span><span id="661d864d72d644dab432eac03c9cb1a0" class="nlkfqirnlfjerldfgzxcyiuro">320</span><span id="ea6d30f98efa4b5d850eae9c831e1e3e" class="nlkfqirnlfjerldfgzxcyiuro">200</span><span id="94a1d37480a8423e9aa77eb70210b62c" class="nlkfqirnlfjerldfgzxcyiuro">320</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="a9056721918646f9a99566994c50b443" class="nlkfqirnlfjerldfgzxcyiuro">更】地址</span><span id="19e1dc3dd1404af79473823ca94841fd" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="3f5f616fe2c94826acd096f702676e46" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="0886337ac29141bd8826012aea394275" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="5577ccc67e5b479fa4b43d9e242e82f6" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="e376a54c2c234627be153df7e26a3e37" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="ddffff5df68642638e23081bf042f7df" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="017b4ee497934106ad9770990d6fccdf" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="ddb9f29dcfe642219f69e2f0e70d0cc8" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="c25c1b692fb344ad8360d8ca05b97642" class="nlkfqirnlfjerldfgzxcyiuro">更】地址</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="024cf417948f4c6487463a8e207ca891" class="nlkfqirnlfjerldfgzxcyiuro">市蠡湖大道</span><span id="5481e44045cb44f4bbea62e3283346be" class="nlkfqirnlfjerldfgzxcyiuro">1800号</span><span id="bb8cfd71ef91412aa47e6f29df2e3ed7" class="nlkfqirnlfjerldfgzxcyiuro">江苏省无锡</span><span id="a82844b2a26740fdacc81c172c7fcb14" class="nlkfqirnlfjerldfgzxcyiuro">江苏省无锡</span><span id="64daa81a1e654dea82c2925ac8f482d0" class="nlkfqirnlfjerldfgzxcyiuro">1800号</span><span id="b9eb654ca29e49f38c3f878cc2960aeb" class="nlkfqirnlfjerldfgzxcyiuro">市蠡湖大道</span><span id="ed5414fd2fa04c51a2038f64c381236f" class="nlkfqirnlfjerldfgzxcyiuro">市蠡湖大道</span><span id="35e27f0c46dc40f4836e0d522c727a29" class="nlkfqirnlfjerldfgzxcyiuro">江苏省无锡</span><span id="ac28f72901394c94add6199745f59cc3" class="nlkfqirnlfjerldfgzxcyiuro">江苏省无锡</span><span id="b83342667c1147c1ab7ac3d5b8bf8827" class="nlkfqirnlfjerldfgzxcyiuro">1800号</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="0ae2a731683446d3a4296e6c99875d3d" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="8b6276ac60ec4e7aaa49b94573e7b01a" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="8e954541b2dc44e4815a22ed113e030b" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="28b554b900154a12bcad497a6b5bfd84" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span><span id="6a6911ad1d654056979f776a814235bc" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="fa86405795f94213a36dfe8223084817" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="286c53e746ff4d769d25de97bc3ad083" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="5825bf5edafb4c16ba440b815c2fabc0" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="9b86d8894b194f76ba4a6c79f6d72e40" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span><span id="2c0b944f912747fc9934ca64125af4e3" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="5034aedfb167439c9add7326757ddf61" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="bd553a3bcf494a56bb8841e00bdfcf87" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="bfe62b5a920d408ea8153f693c1b8604" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="074119430fa143c49c40afbea0e6d0f8" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="667baa447de84eb88a40993434b97ba2" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="ff02c362026845738dcab7973ec46824" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="b87587dbd7384845939978e648cab804" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="d244fb0d9fbb40e5a8e3ad5572a35d4f" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="c7bdd36dc0fc44bfb4a2de9ae32c6ab7" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="5b3417908fdb47a58d11a58367887354" class="nlkfqirnlfjerldfgzxcyiuro">中国</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="a02c805aef6142009e9451931549c018" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="001831127a324b378e0a9ed9e5224bdd" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="299b6c32dd4742dba553abe48199bf18" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="eb25ff6c9f4148858e019760a8bedddf" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="18d1542f6b344376816c17cf71cabea9" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="3466c53a71224ce8a8a4ef61597f43c6" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="7d2bac12347c43d88a9836569f9ab123" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="0729f5df5fe14a20a2f0dab0698c9867" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="4f8ff8be4f7d40a7b32b8274f25f3a60" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span><span id="7736e64ce9924d839e9a8f04116bef6f" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】经常居所</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="c903172c88234a2db501152b275a8402" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="907cbc69d59f467c86f5428c0fa1fd90" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="0ac04ce4f5fa41a782678969b8bca6da" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="f5b31bf0f3ab4ebf87672f174d61d9bd" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="d1deef5bd47244429034e67b95e6f45f" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="ad942e1b4247482885139b6f83cdc470" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="c8b36f9df8024d51b79b78ccbe5014c5" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="753d3f81f6664f8cac05a08f37a6687b" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="fb22917655e749feb9642a096286dd57" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="2c716da4009f4526948b2228aecadc68" class="nlkfqirnlfjerldfgzxcyiuro">CN</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="e5b772a9ff5744f786e71a4e12bfa64d" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="83aff5e2dcee48708c2ef170700c3a80" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="83e6e6a4ed364fd289aa20c7eb1f82ad" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="2a081114d0004bb19eae7a8441bbc2ab" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="a36442af7bcd436ab353f993c3a9a7dc" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="05b2cc985f36452db0d71d5835e2f890" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="5ed5cb9dcec647ca908744b1f3ee200c" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="86c03e5a1c0c472ca0f029d5a581b6c6" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="9e5573805c4442e0bf1e70879438aca7" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="a4b50c65d76a4c45b92a58163b2a6109" class="nlkfqirnlfjerldfgzxcyiuro">】类型</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="a9d4cdbaade2487ba8430b364b5edf6b" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="f511a59131b54bffbac2490bf7fdeef3" class="nlkfqirnlfjerldfgzxcyiuro">院校</span><span id="878904d56a7c465f93065f2b37e1f801" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="07e7dc51304244c0acbdc373fc6f977e" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="b7df7a45b2dd48168076aaecf70fdc0a" class="nlkfqirnlfjerldfgzxcyiuro">院校</span><span id="17fd4305405e46d5b710b5d77e39a0c9" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="ebfa43c673f1433c981fcb68e8a08e3f" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="eb59601106d144b7ae5fe57702fd7a75" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="6af352b8dce94f73b63c23546a4efdae" class="nlkfqirnlfjerldfgzxcyiuro">大专</span><span id="5cc7df40c3d143e69e4c775bd77d11e1" class="nlkfqirnlfjerldfgzxcyiuro">院校</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="d984f766fd814feca395e0274c3ee948" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="56c7652792c7461f91c95a303cacb36a" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="b50dee237e1e4b089b329cbc8eac6261" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="a03ec9b9613240cf86f0684a7372c8de" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="a7d1c38fbfe4445da6d8f43ffb58a899" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="f0d21371ee134438a9c90cf8624ee9c8" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="7a5058d9468a4722b765f4893560ae3f" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="6401630a2f0d4436bc565f9a14464dc1" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="6db3e98dea634eb8bb18c24efb1218c4" class="nlkfqirnlfjerldfgzxcyiuro">省份</span><span id="d1623dfdce9b49f7a6bc7b8a639a79b2" class="nlkfqirnlfjerldfgzxcyiuro">省份</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="405618e0f45c41bf8cb1854a79767248" class="nlkfqirnlfjerldfgzxcyiuro">江</span><span id="e1c7bed46cf84638a149fb83daf33862" class="nlkfqirnlfjerldfgzxcyiuro">江</span><span id="17d8f62225b74b9e98be46992b38af5f" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="667bfbecc7c64f3f890e4149f923f4f4" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="223d582b6b0d49ac8c78f6b6b43c9e40" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="fac60f7ec0fc42d6b1ee8c8f6cebe7b1" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="ec5110ddc93c41ed9385d48a0c9403a9" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="a9b51e96ed4e418396bfd638bc9537e2" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="42b3bd7575384af7b9c193ded01f1e19" class="nlkfqirnlfjerldfgzxcyiuro">苏省</span><span id="0411de75fb31411b98242df6a52adc04" class="nlkfqirnlfjerldfgzxcyiuro">江</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="51da06b2d54d4234bcedd15d8d94194f" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="55907d2ef74143feba959e8e58f7781b" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="6dd10afaec894feb965bcc00b11ea2ab" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="9f040dce0fe947fbb2e611ee8b2a8f2a" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="c2c39d7ede104fe68e511184606ffb11" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="786cc2d88b7b410493f30920924746f2" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="64a33dec258143e9bacefea55ca1c144" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="f355b462179f4fd28157170e9c1b1648" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="94ee0d06ab8e498b87720cc3a6afea13" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="bcb5818cdd004fcc91351f79b6f8a7ca" class="nlkfqirnlfjerldfgzxcyiuro">变更】序号</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="f09058f63de74bd0985bb3287711e912" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="474e328a6970408e9829e7291be1fb15" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="59076f19432d4613a62bc9ea5a10064d" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="851c4e249c7a42c69eb45e4092f4be61" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="433319941aa54b4cbffb5bb579f9ce61" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="e7c6501b5793483292fc63576eadf03c" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="cef89e5bfa694ef6b1db2502b832016e" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="cf0ba2756fd745bea830ef572edd92df" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="fa6358f487ba4d7a94c523ffff131477" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="905290543e8c40d1bb109acd2a9338a6" class="nlkfqirnlfjerldfgzxcyiuro">1</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="5fd2c574398742c185ed8472ced61df4" class="nlkfqirnlfjerldfgzxcyiuro">【申请</span><span id="dd9eb6332abb434d8ebf6f599f95ea46" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="bb60a9b39f894ee881a72f4d72d62429" class="nlkfqirnlfjerldfgzxcyiuro">】姓名</span><span id="03dc427d506b4e93aeeaa851f53d0af9" class="nlkfqirnlfjerldfgzxcyiuro">或名称</span><span id="05ec9df2664d4d729a2e5479e164144a" class="nlkfqirnlfjerldfgzxcyiuro">】姓名</span><span id="e3e2b0cc48b045089750570fdc91eeac" class="nlkfqirnlfjerldfgzxcyiuro">【申请</span><span id="258b2c13be724547906238ec209ab720" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="b76ce2c46b0041f8b2a80fa300ab1848" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="bf32adcf4b834101bc801e01ae5b49b0" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="3fdce51ab92b468cb6d0d5f17b405324" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="ce7803cc6eb949b68d9e8e536fed39e7" class="nlkfqirnlfjerldfgzxcyiuro">南</span><span id="92214e43f0d343448194cecf7a43916c" class="nlkfqirnlfjerldfgzxcyiuro">大学</span><span id="92e148d728464afaa3d1a93eb2efee89" class="nlkfqirnlfjerldfgzxcyiuro">江</span><span id="4319edb2d7e3496da802ad24e01a22aa" class="nlkfqirnlfjerldfgzxcyiuro">江</span><span id="69234dd1017341a9954c0aa4f2999a6e" class="nlkfqirnlfjerldfgzxcyiuro">江</span><span id="3727aca014a34af1a735da32a1abe967" class="nlkfqirnlfjerldfgzxcyiuro">南</span><span id="a69835c41851414e9f4f9e3abaa99777" class="nlkfqirnlfjerldfgzxcyiuro">南</span><span id="7c0b9e9b338745848d548ac7c680caa4" class="nlkfqirnlfjerldfgzxcyiuro">大学</span><span id="758e49defef94309ba6b73d0ec254b22" class="nlkfqirnlfjerldfgzxcyiuro">大学</span><span id="1db694f0481144ab82b263b663388d1b" class="nlkfqirnlfjerldfgzxcyiuro">大学</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="ce3d6ebcab284ece91ec758828ca0ac5" class="nlkfqirnlfjerldfgzxcyiuro">【申请</span><span id="3951a9b03e7f4e90a6c994f45ed7053a" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="836e76c575e340d89677c9953ac45ada" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="f3373954a65449cbbbe642fa832ef9e4" class="nlkfqirnlfjerldfgzxcyiuro">】邮政编码</span><span id="c6832ae102c04aa89b77267095b955b3" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="c2c64c2d7d3a41a9bd93c34d68ef3999" class="nlkfqirnlfjerldfgzxcyiuro">】邮政编码</span><span id="fb3cab8ed0144243a1fadb2cd4ff39b5" class="nlkfqirnlfjerldfgzxcyiuro">】邮政编码</span><span id="8503f6a182584b7aa551351a0f10be27" class="nlkfqirnlfjerldfgzxcyiuro">】邮政编码</span><span id="068f3c42435349c0b13604f03f58c5f3" class="nlkfqirnlfjerldfgzxcyiuro">人变更</span><span id="a4e95db112934553b0151d73a80abbdc" class="nlkfqirnlfjerldfgzxcyiuro">【申请</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="ab86cf9940e14b128247eeb80fc6dc8f" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="7d71969a928a45c8a727c4ef1ae6ec9f" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="576ed2e4d81544da93eacd4fe21163e8" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="46e13f6dad6b4fe7be72dde85d98ba5a" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="5944dae6895d457d8c0d850e8327974f" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="dd2b14f0d3d941cb86f79203a5e490e1" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="6d05183eb99741edb5de89c8149302d9" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="d5cd71e2f7e248caa5c8e675f576daaf" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="b78eb14266824049af97629c4ad88641" class="nlkfqirnlfjerldfgzxcyiuro">214122</span><span id="cc0854d210e44a828e910be06de5ea4e" class="nlkfqirnlfjerldfgzxcyiuro">214122</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="a249c3170ff742ffb3703cfa49ca533c" class="nlkfqirnlfjerldfgzxcyiuro">】英文地址</span><span id="0bda4dbb07164f8c90bf81a0505d7eb4" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="8dc89dc5180c464ca41aeb6be436776c" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="d0737d0b88ed4204ae9191151fd828ca" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="0eab7a3943ad47e4a37d1d361afe2c18" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="ae0c873e8c084182be58c137b12de595" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="6863f056da9a43768b6ba3481acd54a0" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="22b7c473f64c43309b5c344629d48a5c" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="d39058e0f5944c4aace335c5d3df8235" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="c7d527f7a28f4ce2bf6b07ce000e9448" class="nlkfqirnlfjerldfgzxcyiuro">】英文地址</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="a197a5a24f024423aa837eda0c7799d1" class="nlkfqirnlfjerldfgzxcyiuro">【申</span><span id="9065e50c1ac64f35bbfa5a95f4eec694" class="nlkfqirnlfjerldfgzxcyiuro">【申</span><span id="5ae47bba05fd4cd2b6065847bb229dc7" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="909303922ead483b98c637b07304c803" class="nlkfqirnlfjerldfgzxcyiuro">请人</span><span id="71f40a0b671a45eab5ac9d546a7421de" class="nlkfqirnlfjerldfgzxcyiuro">】英文名称</span><span id="9a3d47b18f8c45779ad20fe84eadccaa" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="8a89da2a3c4b4aaa88132e43c44ac2f0" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="20f1324dffc04555b682da49705efe8e" class="nlkfqirnlfjerldfgzxcyiuro">】英文名称</span><span id="7e81db8fd65b4479b207070156e08d30" class="nlkfqirnlfjerldfgzxcyiuro">】英文名称</span><span id="567a22b4ea974d359b9b14eb17170764" class="nlkfqirnlfjerldfgzxcyiuro">【申</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="f56e8640e6914bada036071bb7f70cf8" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更</span><span id="0516780a5d2d4117b8bc68f2d9c44d68" class="nlkfqirnlfjerldfgzxcyiuro">】是否代表人</span><span id="f3e7b87167eb48c0b063cae3a239e8e9" class="nlkfqirnlfjerldfgzxcyiuro">】是否代表人</span><span id="aeaef4527acd4fa5b9ebedfe52795d52" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更</span><span id="c0a4e98dbc2b49659f3ed7350d27f755" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更</span><span id="19f9c3bbe0bd48b28b2fcd228f6685a6" class="nlkfqirnlfjerldfgzxcyiuro">】是否代表人</span><span id="22d98e6bde3d4ec98d1188c29f034cda" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更</span><span id="7f78df46a2f54998a1a798e9836ec85a" class="nlkfqirnlfjerldfgzxcyiuro">】是否代表人</span><span id="502650c7453247eb9d1d615d3ee11b83" class="nlkfqirnlfjerldfgzxcyiuro">】是否代表人</span><span id="4b82d9aaecdb4ef4b5d796c2fea12739" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="ae2724013a664bf3a8e97f6a3514039e" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="70159764b5614ac9b4460465cb9f104f" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="a106f0a0b08542a5af7d49f5c0f8d69a" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="204a628ccbb5443fa06d3ad0c1508d71" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="38dddacedb464e5a9582846618e1dc27" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="8ee06f6cfdfb4a1eb0baa9b004d84f8a" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="b4cf4162177e413ba421e039127dd88b" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="79b97dfd2bc94bd48da43f6de96b834e" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="48c5bc89910f4e36b44dda17a032e5c8" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="8af184dd5a18441b8e463dfa53d26731" class="nlkfqirnlfjerldfgzxcyiuro">是</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="abb8e38dc2b1487c8e67881c55516d75" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="1f033aa11f0e45e79e348480239c9f10" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="ba714bc3e9e44382889847825ea3cdfb" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="d9d6254a2fff41e3840d6493365d1584" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="3d95fca1703e4b34bf10b448c7991828" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="e857494fed9f43bbbeef5d5d078a6104" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="c2d850139e3b46498ae2813b4a326f97" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="4f2d3398905b421a952142b7b12a2a50" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="9807cf03b486487b8d7f557319c09081" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="89449d8b65a24b849c1197323b65beda" class="nlkfqirnlfjerldfgzxcyiuro">变更】城市</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="e2237006739346d0a50847cda52c4492" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="d1c85faff7bb4259aaa597e15b05db64" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="278241b834a94485a454688a886871ff" class="nlkfqirnlfjerldfgzxcyiuro">】地址</span><span id="f44575a8e01549e58ac6cb4be876fd0f" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="9522aeca05c4448cb825c284986e0330" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="fd34a79b5e2c4b47bb372801ac10e654" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="eefba9c5099a453a88e3008e2f060935" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="00fd50f0639749b99533fbbd833fefb5" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="1a09536a1e804125b648f99aa09e22fa" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="bbdc4c57a00540da8fa80799735cc343" class="nlkfqirnlfjerldfgzxcyiuro">】地址</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="89d3303c0902458bb2f7b80b56803767" class="nlkfqirnlfjerldfgzxcyiuro">安徽省</span><span id="ff1168dc0e9b463c8fb5a95219405100" class="nlkfqirnlfjerldfgzxcyiuro">0号动</span><span id="e07fa8db13da4b4a94add66a0112dca7" class="nlkfqirnlfjerldfgzxcyiuro">合肥市</span><span id="4f145ed0f72548be905b608c8a2bf206" class="nlkfqirnlfjerldfgzxcyiuro">0号动</span><span id="52790313ca4f4f188a359da11841dce3" class="nlkfqirnlfjerldfgzxcyiuro">望江西</span><span id="49a67a0a2fcb4307934d8030a41a0c10" class="nlkfqirnlfjerldfgzxcyiuro">路80</span><span id="aa317cb3cab44382938e16903b3fc511" class="nlkfqirnlfjerldfgzxcyiuro">0号动</span><span id="82aef64b879244dc91ac94a402ceccd5" class="nlkfqirnlfjerldfgzxcyiuro">漫基地</span><span id="c951fb4c2e7e4c039cead13662d28446" class="nlkfqirnlfjerldfgzxcyiuro">A3座</span><span id="4ae4d39d7c2340c6a7f3f4ad99fa273e" class="nlkfqirnlfjerldfgzxcyiuro">505室</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="71241333e8ca4d2b9457b81782872a9e" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span><span id="9c23134569af41b5b12436fde5831d81" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="eeac2072b31b4e40a61e7a422a522db1" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="e3b748fe8147418c9a53466157f618da" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span><span id="9c998794300144e3bd2e22e29bd4702c" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="f739b479b66846a6a59ccc8369001835" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span><span id="f0fd79bb5b4b46dcbebea37ba90f2a99" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="b891457c7b93458c9dbc74aebda9b195" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="967b852fb644405a9c9d1b4a92bceaa1" class="nlkfqirnlfjerldfgzxcyiuro">变更】国别</span><span id="e2d067a6bbe2475caa4130019eac7006" class="nlkfqirnlfjerldfgzxcyiuro">【申请人</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="a62d0bc7e0384c688f39cb92a738b973" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="2e8d9bf79a9c4b1eb5ee36961053e12f" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="1c136f1588494586ac1ff6b64adf207f" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="844c23316a6e46e3a462a671d33b5bb1" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="e5eced931182475687a87d65cddc53e6" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="02bd97f5063045b48acd2bd8892cab9d" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="9db449e4be0743379684482a606afbc8" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="1b9c9cc4866b43eebb09f3c03dde3596" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="851a2c378f0f4fca862291d42f582970" class="nlkfqirnlfjerldfgzxcyiuro">中国</span><span id="bfc89fa219cf4f3aacf4278dbab9ccfd" class="nlkfqirnlfjerldfgzxcyiuro">中国</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="66401b361b874dc897ca90196f3d3ccb" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="8b299f803d364133acd3c36c0cdc5e25" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="724b05704818403aa4198f3a78bff60f" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="af49eb02f9fd4afe9dbb863a8986b979" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="045f81127c484e85ac14988fb3599688" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="9fe3405800fd430e97e5b3476c087fd7" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="a4d89b1d87f7482b9c84a9301ff2c2aa" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="380c8c36ccfe46d3ac66d5172cabebc8" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="35ab281c55524d79b97f258590bf723e" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="7d969f5bc28f4880bc7d20e59acc3af4" class="nlkfqirnlfjerldfgzxcyiuro">经常居所</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="af9e71ec7f7d4113ac8fb9d124ec1bdf" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="ef4b1c197db24e7db6038e6f36a69f8c" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="0d0fff9545e64fd2946120f65051a005" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="cac822d0dcaf4b8496f33a43d8c7e9db" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="938010354f784b6599de59f73e110b10" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="b673796ce2e24686823fbfc10bfd0eb0" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="d4e9820ed03241c38c1a263757cbc7f2" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="13a247e724004bfcbe316fbe652ac2a9" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="0c599426467248d6814d81a1c2029fee" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="a43f15df4a75468b889440e418e5da17" class="nlkfqirnlfjerldfgzxcyiuro">类型</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="efe29cf9cd1e44958aac387e5549c531" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="5fa639273f9643b6a8374f93eb37ae25" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="a3135ab3544f4c4f910492ba341ceffc" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="30160e5cacb440ff94541c190e0bb827" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="e3f3e79f346e4d42a34c29fbbb499d81" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="2cd526c09f544bf5af8f139031296eb6" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="c97c1a168f0447e19febfe5d15412922" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="a3a3f6263fce49f498c61606f355413c" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="53458327841a40b28897f2f2930775b6" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span><span id="6ad7d4a1ee0c4672aedbc6b0364f7913" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】省份</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="94be62399e3143f09ac4ad19004db61e" class="nlkfqirnlfjerldfgzxcyiuro">】序号</span><span id="51c309ad0bcb455fa09587eba8de11b5" class="nlkfqirnlfjerldfgzxcyiuro">【申</span><span id="ed15eec390904db2ab61633e35fe0ac2" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="121e741abca94c21ad05b680d9937011" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="aeeff3e5dcb54230af33bcdcc0739577" class="nlkfqirnlfjerldfgzxcyiuro">】序号</span><span id="7837d4fcff694879971952abacb0fd68" class="nlkfqirnlfjerldfgzxcyiuro">【申</span><span id="783f18a2410345d6af66960f422afed8" class="nlkfqirnlfjerldfgzxcyiuro">请人</span><span id="2ebe0a76356d4661bb418d26531f2ec4" class="nlkfqirnlfjerldfgzxcyiuro">变更</span><span id="bda854d61c5e463bbf843709819ce65b" class="nlkfqirnlfjerldfgzxcyiuro">】序号</span><span id="9514d39d01134e22852b9a01be356ec1" class="nlkfqirnlfjerldfgzxcyiuro">变更</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="87024da20c764a419a471ac174eb2ca0" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="3c445ef0d1984c13a4808b54a52f861d" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="16b3dffce7e24b5fa406fadd569a09b7" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="0725f1dad26d47108250c2cb6e466f44" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="6aa05b666117412cadb2a104b643216c" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="e9eac1b7dbf744a0a75e24dd656767a6" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="29e8044c22204ab29dc696cde1356ec9" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="3764e34cc1b141e5a5d967fadd906d29" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="c2c0ce8d182b46b2a84afa5a85fd0437" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="40cf2fd87dcd4d52be4a0065659aac6c" class="nlkfqirnlfjerldfgzxcyiuro">1</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="aacccfdc6ba34dc4a364f6921d158f60" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="a8397de2464746839621fe8f4f09174f" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="9861afbc7c934947a0e489bd77017567" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="ec714488765d457da66c20995b3fdcf9" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="1232509d329d4d93b5e1cea0c4f97c8b" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="711b20944e744d35b73ae4ce084de348" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="11dbf921bf0d48fd85f6b8859060170f" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="504c798ca9bb47b480e59cb6e2e3eb53" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="114a4cf47ba7474c9ac7fa75162fc67c" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="8ebc6ad55fb749acbc11dd1f01dc284e" class="nlkfqirnlfjerldfgzxcyiuro">姓名或名称</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="514bd802a1df48dea670160e690e14e6" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海</span><span id="8f2360a7664843abb2b2d9779f9a39e6" class="nlkfqirnlfjerldfgzxcyiuro">博兴生物</span><span id="fa6ba18bc4c6462b8ea274bc3ca8fbbe" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海</span><span id="c847c18041024b66a8fb53427e7cc40a" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海</span><span id="c61902aa74e8445b9f260f3cfe7cdea2" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海</span><span id="e96484c43e434d65a19481595ce3f95f" class="nlkfqirnlfjerldfgzxcyiuro">博兴生物</span><span id="84dfd190dd19488496d27a1918c312b0" class="nlkfqirnlfjerldfgzxcyiuro">技术有限公司</span><span id="b5bed83c4f1544408093603cadcba98f" class="nlkfqirnlfjerldfgzxcyiuro">博兴生物</span><span id="8007de9f73b94a499c813773f3d2d4d0" class="nlkfqirnlfjerldfgzxcyiuro">安徽瀚海</span><span id="bbd6c417a5b14a4cafa6a8df5acdfb10" class="nlkfqirnlfjerldfgzxcyiuro">技术有限公司</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="73353fd248b44e6e90ad6715463c3ecc" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="1affced8bf874aa28d9916aeaac98e74" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="f1dd533114174502b9e7f84f454529b6" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="dd21f5123f9b4dc7bb102b702ceac30c" class="nlkfqirnlfjerldfgzxcyiuro">更】邮政编码</span><span id="e8426cc54bab43738a1738a4cba39d28" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="1c56116076374bbba51ffa0b433564f4" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="665d49120d6a47bcaaf96600ac602d60" class="nlkfqirnlfjerldfgzxcyiuro">更】邮政编码</span><span id="d9b04b372f834668a50dca4906b7d411" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变</span><span id="792934dbaf6f4d8e85585505d8cf3696" class="nlkfqirnlfjerldfgzxcyiuro">更】邮政编码</span><span id="a16c0503e3b04ef0906e66d18bdcd962" class="nlkfqirnlfjerldfgzxcyiuro">更】邮政编码</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="5061b5174f5e43d99d86aa8c9cd132f2" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="3d902f1be945416887362dc1cab3c992" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="d182ad78520249038e01c76c9a0a85b3" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="21a8a4179853443bb5717d3b5376591a" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="2d666be60ae5428b80b3213eff8654b0" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="a1e13c93babf4e3697ff98289021d1f5" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="4709a082468e4d94ae390387c8d598b7" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="226fc6b61ce94201a81d8d462bcf9d8b" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="309504dc87c04464b8d76ca980f0c515" class="nlkfqirnlfjerldfgzxcyiuro">230088</span><span id="25e7efdc69034dab8ebc37ecb31c9bae" class="nlkfqirnlfjerldfgzxcyiuro">230088</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="955a8e27dfcd44cba65e5d932899c97a" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="463afb370991488189d95aa53ba0c0c6" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="ee0f2db6286d45d3a2f6b51cabc0de65" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="dd221886a51f42a7a5ef6880bc9fee7c" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="3e08809db7164506a5d2bfa59951245b" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="a6008b93de5f4c1baf92ad4a45aef1e9" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="d3eb2bb4b87a4538bfa948bbaf13e0c2" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="cc9e3338bff34c6ab25427bcdef2f2a4" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="a4794a43ed514af5af04ba8c0ad74566" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="a7bc5cbdae53420b86aaf062f7da3267" class="nlkfqirnlfjerldfgzxcyiuro">英文地址</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="6dfe0d8c8bba43a29887fbeb638d278a" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="46ca4c060caf4e7f9e09a34bbff08997" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="8ce50733b62b48ada6c37a272c87b419" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="2fa9b5d3ce43497eb5f409523235b5de" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="b2381f7398a34ae4a3aa4a17e7d09774" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="c85291aa8ca348ed9e78757f4da86e67" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="095c562a840c463e8c135813bec6e920" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="296939b5971248eeb9e5cd98d484301a" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="f4882b16410d461b8d8693b065c0d280" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span><span id="75699125198849d9861aa4d07a37df92" class="nlkfqirnlfjerldfgzxcyiuro">【申请人变更】英文名称</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2014-06-05">2014-06-05</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="52ee8f38023e4c24b5fa1e36d290e534" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="a6ad5dd80b234e458fb26a8eb03d2fde" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="48469b7c57524411b5fc39d0dce00145" class="nlkfqirnlfjerldfgzxcyiuro">【</span><span id="f0d931d73edd4ea5abd5412059d2f27e" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="1c80fe7c62744c63814a444fb270fa44" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="0ea42f46bc74409d9df4a7ed329d6166" class="nlkfqirnlfjerldfgzxcyiuro">人</span><span id="40e547666d924264b30b470871dd8822" class="nlkfqirnlfjerldfgzxcyiuro">变</span><span id="750629d3498b44b8a1f163b0de35e862" class="nlkfqirnlfjerldfgzxcyiuro">更</span><span id="87d52fec0393496cbae404eade10f14c" class="nlkfqirnlfjerldfgzxcyiuro">】</span><span id="d101d222173743e9bef5c385385ece8b" class="nlkfqirnlfjerldfgzxcyiuro">是否代表人</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="0118f19b968144ec9d3bd95423d786fd" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="2469368476b84239a1e892b26c0a4ff1" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="1a72695c28c748c2990709351276410b" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="5181aa236d1a4d6d9531f3bcd600c7c0" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="37a2c07c19504de6a40faac4a51dafb8" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="a19a8087413d43568b7400e99b51b7b4" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="7014bc2f79f94d668067395f476957af" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="8def11d946a24b49b5e74a8ccd8daf0b" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="47aa6f1a453c4faf99d99fafe22df196" class="nlkfqirnlfjerldfgzxcyiuro">是</span><span id="90b1053a01d54e458d5684e23f147bb8" class="nlkfqirnlfjerldfgzxcyiuro">是</span></span></td>
								</tr>
							
								<tr>
									<td width="15%"><span name="record_zlxbg:biangengrq" title="2012-11-28">2012-11-28</span></td>
									<td width="25%"><span name="record_zlxbg:biangengsx" title="pos||"><span id="23b007ca781444909f47e0733521fbe2" class="nlkfqirnlfjerldfgzxcyiuro">【主</span><span id="00bce261fc1e4c4fad3ac9cebb579fb4" class="nlkfqirnlfjerldfgzxcyiuro">著录</span><span id="e331f0e173574e7887c886c2970f56e6" class="nlkfqirnlfjerldfgzxcyiuro">申请方式</span><span id="48bb1aef3d104829b8013ae9e675248d" class="nlkfqirnlfjerldfgzxcyiuro">项变</span><span id="5ae17d05076e4c72bbcbe6602fbf8f37" class="nlkfqirnlfjerldfgzxcyiuro">著录</span><span id="112264961b054783a1708f5bbe7e527f" class="nlkfqirnlfjerldfgzxcyiuro">著录</span><span id="c95c960013ce4934aa323a50e2235a53" class="nlkfqirnlfjerldfgzxcyiuro">著录</span><span id="cc474bb208ee494683a51d508a0877b7" class="nlkfqirnlfjerldfgzxcyiuro">项变</span><span id="cccac70524eb4402a83546a55264f0e1" class="nlkfqirnlfjerldfgzxcyiuro">更】</span><span id="35dc5202d0f446dfa7bc8974f0d41dbf" class="nlkfqirnlfjerldfgzxcyiuro">申请方式</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangengqnr" title="pos||"><span id="9a81788ab3c443c38f5b4e6511241005" class="nlkfqirnlfjerldfgzxcyiuro">纸件</span><span id="3a207403544e4f0d9b1cdfff9e011157" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="85853d99c64548138159a94fddf01b1d" class="nlkfqirnlfjerldfgzxcyiuro">纸件</span><span id="effea12421044d44a88f31fa588c19ca" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="2050cc630ddb41b69e09a17cf4d46204" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="c331ab0ba11f416aa1bcabb6f193f976" class="nlkfqirnlfjerldfgzxcyiuro">纸件</span><span id="79b8d78623e14adf82e656f16806c4b6" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="1a215319790845d595c59ce5c478ddda" class="nlkfqirnlfjerldfgzxcyiuro">纸件</span><span id="2143381a29a7432ca6c58704074b5a70" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="d11efe16efc342e397f29f930ca129fc" class="nlkfqirnlfjerldfgzxcyiuro">纸件</span></span></td>
									<td width="30%"><span name="record_zlxbg:biangenghnr" title="pos||"><span id="64e06741f59444588ca4a7230cdec980" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="8bd6a52465c54029bac3cd79d1931536" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="e37b285f44c74b60ae0832e473272133" class="nlkfqirnlfjerldfgzxcyiuro">电子</span><span id="f16078d5ccb54ce59baa178604114f48" class="nlkfqirnlfjerldfgzxcyiuro">电子</span><span id="bc48fda003f041318955edd4fb1f519f" class="nlkfqirnlfjerldfgzxcyiuro">电子</span><span id="7935dd62aebe4c08b2eb651988b08f3d" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="340f156d334246be919d59ab0392d009" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="0272fa25b3e946a2b66fe2c449502d55" class="nlkfqirnlfjerldfgzxcyiuro">申请</span><span id="9c6d3883c77d4f46bd2e646157d722ed" class="nlkfqirnlfjerldfgzxcyiuro">电子</span><span id="353d334cc60445d380a875fcf5c12e98" class="nlkfqirnlfjerldfgzxcyiuro">申请</span></span></td>
								</tr>
							
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="ft"></div>

<iframe id="downFrame" frameborder="0" height="0" width="0" ></iframe>

<span style="display: none" id="6131323033666030316c3a39386f6c3d287424202d24742e7a2b2c287a2e272d0c401a45141117461d1f1f1b1c194a175253010403570356595809020e5c580e256d747175712275707d782a282b7a7960633061643367673e69386a3e3e6a3b54594e065552545e5d5153595e555c5b11444b11464d4e414e4a4e1a4f1e4a4fb3b1e1afe5b4bfb2e9eabaedbdbee8bfa4f5a4a4f6f6a5a2a9aef9aca4a8aca69896c4c2889dc397c9909a9dca9c9fcc8785d4d68dd7d38e8f88dbd8dd89dadcf7f5a4f6f0e9fef2fffaf9afa9f5f7f6e4e2e6e0e4e3b7b5efeceeedeebcbdb985d6d7d480d6cad7dcdddc8ddc88df8cc193c7c7cdc1c1cec0cb99cacec49ac8353936323635342b6d6a3e3e3f6e68362677237020267771217c2d29282d267e1844131742141417044d1f4f1c4c1e16050751060c0153530c005f5d5a5e0a0c72707b7a7574727e79652c2a7a7f7c7b346362363233626e3d6e636d3a3c6b6901020451555057010e0f4652555f0f584644144446414e434a4d4a191a441b19b5b9e7b3e7b4bfbeb1babaa7babebfeef2f3f1abada1a0afacffa2afa4a8fcfb94c5c1969292c4c19b9f9c9a80cc9fcb8587d082d08d828f818d8a888c858bdda1f9f7f4a5a1a7f4fba8acadfbe1affde9b3eaeab6b3b7b3bde9eeecbdeabfed86d2d787dc87d4d08dd08ed9dadac2de93c4c5c295c2939699cbcacfc9ccc7c63062343761363e3f30316b3e38686c2327277122772525762d7d7c2b282e7b26191047141c4112424e111c131a1f18181c1d0b030c575255010f0a0f0c5b0a0821787a70752124737b7a7f7e282f2a7a32637e60606237623b6a3b386c3b6b6b020253015152525e0e0f535b580f565a4940455f121744144f49194b4e4a484ab4b5e1babdb0e7e4bfebefeabdbbe8bba2a9a3f6b8f4a2a3fca0ada8afadaea8c6959b93c7c7c29f909a9a9c9c9e9dc989d485808699d3d289dfdf898fdb878ff6f5f6f0a0f3fea1f9f0f9feada9f9fae8e0e6e6b0e3fab4bcbfbcbebdb9ecefd887d3d7d2d6dfdf8add8ede8fdcdcd9c990c595c6c597dbca9f9bcb9e9cc7c93264346630363763303c6b3f3534376e7427737175242f2f347f2d22242b2f2642451317101115134e48184d494f1a4906090556500707535c151617105b085c7274217671267f75717d7c7a797479776133346536603767696c623d6c716d69585256060155505009505e0909090f0c14121645104d10154c4f4c4c4e4a5247b0b1b0e6b6b1e5e5beeabdbfb4bcedb6a9a4f0aaf1f7f7a7faa1a2a3afacacb399c7959a92c6c49f9d90ccc998cecc97888085d18087838edc888a89dfdb8cdeecf3a1a7a1f5fef0f8f9fcf2aef9f6f9e1b0b7b5b7e6eee3efbdbeeaecbceae9d7cd8682d68484d1dbd0d88addd8dad892c9cac2c490c7c4cfce9ccd9ecccdc733642e326636653f306a3c686d3c6d3b25232b2b252726242d297c2824247d274216400f17131f431e4a131f154e184e0454540555530402590f0f0e05585f0f2575762568696a6b64782f72792b2a2b33346a366461376e603b386c3e3e386856525b5b560002505f455308555d580e47154117124542151a1b424d4f1e1f47b5b6e7b1e1e7b0b0e9bea6eaefebefb6a1a8a6f1f0a0a3a3a1a1ffa2f8f8fcafc391c7909d91c49ecbcf9f879cc99bca8081d1d7858cd5838cdc8282848c878ef4f4f3a0fca3f2a5fdf1afa8e0fca8f8b6e2e3eab2e5b5e5e8ede8bdefbfbbebd08487d486d0d486dfdb8b88dec1d6dcc6c2c7c4c197c7c4ce9ccec89ecdc7ca62383437323230323a3039333d3422382029242525242671207a2d2f7e7b2f2643181042454012171b1e1f4d4d1e4a0303540b5750000105015c025d080f5c0679757a7126202425292c7b7e7d7a2d2b7c7d636a316432346b3d3e6a686d6a3e06565b5753565e555b0a0b5258555a5e16155e4012401041494f1c1e4e1e474bb8b3b4e2e7e1b6bebeefbdbbbebbb9b9f5a5a4bff0f1f0f1feffaffffaaba6a9949394909cc0949498919bc9ca9d9a9dd686d6d598858780da8ddfde88848986f3f5f3f3f2a4a2fefffefaf2f5fdaaf9b6b2b1b7b2f9b2b3bae0bce9e5b9bdb985d7d6d1d6d4df81ded08fd98add8bd8c095c29097cdda94cacc99ca9ecbc7cd666331373064623f3b3f3a6f346e6f3f25732b242221243b7a7b22787a79292e45471b1210141446491d1d4e1a4b1c165457005607505200145b035e5e0b0b0b2320707a21717f217b7129782a7579773332606a626537323a7538636f6e6a6d56575500555452500b580b095b0c0d5c1444104b16134e4f4a4e56574e451c4ab5b5e0bab4b5b7b2bce8bbb9eeeeefeba4a8a5f2a2f7a3f5fefda2afb0afa6a9c39491c6939190c1ce9dce9c9a94ca9d85d5d78a83d7d584d9dd8a838f918889f7a3a3a2f0f1f1a3adf1feaeaef5f6aee4e1ebeae7e1e5e3bae0edb9bdeff2f3d0d1d3dbd7d4d7d5df88d9d9d88fddd8c894c292cd9092ce9dccc8c9c89f9a9b2c623b33373431356b3132393f396f3d74732723252423257a2b2d2e7d252a2f120d0e46114711101a48134d4a18191b0457050b02500106590d5f0a0e5f585e7675266f7c7627212e7c2f79282e2b2a646965636c3664323e686d6b6b6d6e3c53005a53485553055a0a09525458085c46454741101746134f481e4e444e4b1ab2e7babab4a9b3e2ecbce9e9b5e9edeaf3a7a6a4f7f4afa7a0aeaeaffeacf8acc5c4909394c68a9f9eca9a98c998cf9ed381d1878387d5d688df8a8985d98bdef5f9f3a1f2a6f0ebf1acfffefbfef6ffe5b2e6e7e0e7b3e7babfebbeebede6e8d9d5d1db858687d0c488de89d9dd8dd9c595c5c595c195c3cd9bc3c99dc8c6ce363260316533373731256b3268396d6b727073777127222f2f7b7b23282e2e7d13171641114042411e4b064d191c1f4e05080300055703035a5f5c595d5e0c0b79712025732322222d2f796760297777643765656233326f696d3c3e3f3c6d6655045251535105540d0c535f54415b591346444646424f451b4e4e4d4d1b474ee3b8b7e2b7b5b5e4e9eae8b8baeca2eda5a1f6f6f1a7a5a0fda8ffaffeada6a6c292909ac7c7c59fcdc8c99d9e9b9f83d18181d6d78cd48e8e888989888dddd9f8f7a4f3f2fdf2a6fffafdf9aff5aaaafcb0e5b7e5b6e5efbebbbcbee8e9eaea8480d487dc83d2d48e8f88ded48cd6d6c9dd94c390c7c7c4cfc89f9ecdcecacb3339633a673c36646e313c3938686b3673293e25202527212b297b297a2d7a2b14121441471010124e104b1a1819181b5452031f025154045d00025f595c080c7424207b2627777f2b7b7e2e2a2f7f7d61693167786166626e68623e6c3b6a6a03555301025d050559515f5f0d5a5758464640474c5942451a4a181f4b48494ab3b9b6e2e2b2e4beebb8b3b8e8e8eabfa1f7a3f6a5acbabba1ffaaafacf9fdfa90c7c79a9092c0c5ca9bcf9d9d9ccbca88d380d28cd384d6948f8eda8f8edadaa3f3f7fbf5f1f5a2f1ababa8a9ababaee5e4b1b2e5b6e7e3ecf5bce8e9e8bcebd6d3d3d4dd83d2818cdbd2dad9dadfd8c094cb90c597c7c1ccc1d6c2c8989bcf64313462663d6333313168333b3a3c3f7372217222747072792829377e7e7c2a18101a40404116171c4f4948151c1d1a0157050a5603500f590e595a105b0e0670747a25727622227f7d282f7c74767a323361616c626166693c636a6e71726a060550005152525451515d5f5e0e5f574514164b404244141d1d4c4a181b4a53e4e5bbe6e6b3b5b4bae8e8e9b8bebaeba8f4f0f5a2f3a3aea1ffa3aef9fcaaa98cc3c09594c49fc59b90cc939599cbca888983d28387d083dc8e88df8a8f8a8df9edf2f0a0a6f2f5ffadfffbfaaffaaae9e2b3b6b1b4b7efede8bceeefb9eebe86d8ced5ddd7d5d38c8ddbdbdddadddbc190cbcac1c195c79998ce9dcec4c7c66137672f37323430696a6b3b3d396f3c24707422752225227c7829297d2c7f7d45181414081442451e101e4d1c19161e01050652560d04550a0f09590a0b0d0c7879267226696a242d7a2e7d292f2d2e32636a673136336e693c396c6965666d580203530506534b5b505f5a0d540c5f4314451540104f47194f19424549184bb5e4e6b4b4b0b5e6a4efb9b8bbbeb7baa4f0a4a6a0a1aff4fafbf8feaaa9acf9c1999191c1c39fc29c85cbc9949bcdc989888683d18482d5898b8289888adbdaa2f9f2a5a7f3a2a4f0afe6e7fcafaaaee4b5b0b1e4e2e7e1ecbfe2b8e5edbcb9d8d083d3d1d5d383df8c88dfc0dd8b8e92c693c0cdc1c5969ccdcd9ec89ccdc864306630323467616d3b693a34216f6a20722a2427702e7428212e2a242f7c7a15194112171244161a4d4f1e15180219080701550400005359005b0f0f0a08072277202277717e76292a2e7e782c7e6362633064376161643e6f6e38686e6d6f59035700575152515a500e5f540c5b0c5c15414a44404e12481f4f4248491d4be1e0e1e6b7b6b3e4bdedb9efeab5bcbca5bdf1a4f0a0a4a0feaefba9a4fbaafcc593c0c592c79690cbcc9a9b9cc8979b84899e9f988c86818ddc8f8bdf8cdfdcf6f5a4f0f1a7a4a1a9fcabf2f9abfaaab5b2e4eae0f9efe7e1eaeae8e5efecba8185d6dbd787dfdf8bdfd9dc8eddd9dcc0c591cbc4c6dacf99c1c39f9dcf9fcc633560376564673f3038393969393d6c242573702673263b2f7c222a787f2679441717411011111e4a1b1a1c1c1a1e1e050757030c5105071415165d090b5b0776757226727c77732a282e2a7c7e787f67603031633361673b3f62773a6e3b6802595552525203055c51095b0e5d585c131017401547454e1d411f42504a4e4eb5b8b5b5b0e7b3b1b9bdebe8b5efbabba6a1a6a5a1f6f4aefea8aaaffab1b2fec2c39ac6979dc2c49acb9b9f949acd97d587858b8c84d5828d8c8b8dd88a8b93f1a7f2f0f7a4a7f6f9affaaef8f8abf8e9b4e1e7ece1eee7eaeae3b8e5bbefefcc85db87d2d7d3d389db8c8d8ad9df8ac3c9c6c390c3c2cecbcaccce98cccbc7342d3b3b34326561383a683f343b3a3727732a77237323222f2a2b227f2d272f18100e1b1d11121e4c11481d194c1c1b5209060a5704070e0f0a08085e0b0b5d2525236f686923757a7a7d7b7c7b797c696266653065376268616e6c3f393f6a520256575d574a5e5d5b580a090e0f5f45124647404d1515404b4f184e454a46b8b7e7b3b7b6b6abeeedb9bfedbab7eda5f4a0f0a0f7a2a0fafba9acaea5aeaec1c29393c193939384cccfcdcecc97cc85818b8ad5818384d98182de8f8d8e87a5f3a4f3f2f5fff4fde5fafbaaa9fbffb6e1e4e0ede2e2eebae0e3eeefeeb8bd8285dad0d78383818adcc6da8dddd7dac3c793c291cdc6c3c9cbcf99cac9c69939386362343c63353a6f6b276e6f6a6c24722724752526222c297e7a247b7f2710161b1a131613444b1a1e1800011616540201030756060e080b0e0e045f5c0d2676207b742773717079797c7a7a622a606634326c3134666b3d3b6f3e693f6654000607525307575958580f0f0c59434543454a444647441b184e1d481b4f47b8e0b1b6bde1e7b6b9b1bebae8eeebbcbca5abf2a2a2f7a7f9abfcf8fea9adaf97989197c09d969498c89e9acd9dcd9e809dd3d2878481d4da8ad9dade898a8cf8f3fbf0fca0f7f1f1f9f9a9ffabadfae1e0feebe6b4b3b1eeedb8e3ebe4ecebd48581dad58485dedc88dedbde8e8b8c9395c7df97ccc3c69e9bce98ce98c99a346232303d6663666c38393d3a3f6a3d28252625382177722c7d2922782a7d2d13151240124411411b4f1e4a4814174951030500511901060a0d0b080f0e5b072320762776277f737d7e28737d7a767d686660326d307a3560606b6f696a3d6802585157515d055e0c0b095c580c0b0d14104b11454c435b54184c49184d1c1cb7e4b2b0bcb1e5b1b0b1ecb8b5eeecb6a2f0a5a0acf7afa0abb5a2f9aea4a7f9989191c7979392969b9acbc8c89ecd9c86d282d0d0d683d28a8c96dada8987daa2f1f0a5fda3a2f3a9afaff2a8afacf7e6e2b3ebedede0b5e1eee3f7ece9ebb9d8d0d3d1d386d2dfdc8cd2de8d8edfdbc9c9ca9596c6c3cec1cfc2c3d0c4989a333532363c3536616c3d393b6934396a2573212723237527202e7c7f2b317f2b44191b4115411e104e1e1e131e4f174c0805530a070507515e0b59095d5c120c7871217b277670242b2f2f7f7a297d2e33676437616461653b38383e3e3e667353540301565d57045d5c5f59580959561248451546404e424149181d4b4f4d1aacb6e6bab2bce0b2eaeab8b3eab9b6b7a0f3f1a4f0a7a6f2ada0fbf8ffaefff9948d8e8f94c196c1cecf939e9898cb9984d7d6818d8180868a89dc8d898d8b8ea1f1f2f6e8a6a7a4f0fbf8affca9adaeb6e5b0ebe0ece0b1ebeabbefefb9e6bcd784db8786c9dfd4d0d9dbdbdfd8da89c7c9c691c2c0cfce9c9ccfc29acacd9a3130326135352a653e3e393c353b6d6a22742027222d202f2a2a7c797a7e2f2f424746134147160b4c1d4f12141f1e4a5401010100045504005a0b5a0e0b0d0875762121277220756478792a7e79792a67636663646134313b3b3f686d6b383d055757510506540651455a085954575b424746454347424f1c4f424a4819464ee1b0e1b1b4b7bfe1edeca6eab8bee8bea5f5f4a7f5a2a3a3aea1f8a3a4a4aaab90c496929cc093c3c9989d878081cf9c818287d2d68683838cdf8ed888db878ef0f5fbf1a6a4f5f3f9aaafadaaaee2e3fce6eae0e3b1e2b1bbbfbcede5e9e6e8d9d8d5d2ddd0d4868a888989dc8b8ad9c8ddc5cbc793c7cf99cbcecacccecaca6437636532333f31386f3e393e6c686a74293e2171777327792e2c28292b7a2b161713414611171f4c1b1c1e1f1c481d5552061f5651570f0d0d5e0d0d5e0b5a7477712126237e737b7e7a72747c772c35676731787967613a6a3e3d3a3e3b680553560151030753585f0c0a08095b594910424a16425a4e404f4b1a1a1f1d48e3b8b1b7bdb1b1e6b8ecbeb3b5efeab8a7a1a3a4a1a3a1bbfdfaadaaa8a9a6a7979797c7909091c3c99f9cc89e9d979685d381d5d0d6d08e948888888e888e86a4f2f0faa0f1a2fefbabffaefdaeabaee0b2e6b5ede2b5efbaf5edeaedbfecefd9d5d686d3d1d283dbdc88dcdf8c8bdb9394c2cbc09193c4ccc1d6cacd999c993933336162356233306f6e33396b386d2829272a242326262f297c37292d2a7c17181a40451c44451c1e481f141d4b1a09525005510753045d5b0f08100c0f0b21752125707224267f7d7d7f2f742f2c67373364616460653e3a6c6c3f71663a02025402005053010a5e5e520d0e0c0c41401617451346461c1a484348185253b5b0b6e1e0bdb6b5e9b8eeedb8b5eaeaf1a7a5a3a5a3a6f2aea0aafeada9fba98cc49b95909d92c49c9acf9f9f99ca9985d0838a808d8782818cd9de8fdb878aa6eda0a1a0f3a5f3f9feabfeaefcfaaee4b2b3b5b5e3b7efbcbfefbabfb9b8bdd1d1ced4d7d6d3d48e8dd8dfd48fdadb95c797cac49492c1cfc8cfcfcace9dcc6562612f606134366e3c3b393f6b376d24757124767727272a7b2d2b2e7e7b7e4312124008091517111c1a1f484e1618530106070201540f5c0e0c585d04060f2671217675706a222d792c79282f787d68673667613165366a3f6c39696c3d3e020252070153534b0c0d58595d5556591144431540471740194c1f1d4a45464fe2e2bbe5e1e0b1e4a4baefbbb4b5beb6f4f3a5a2a2a1a3a7aef8afffaefff8fe95989b9695979292ca85cb9d9c9d96cd8982d6d681d382d489dbdbdd858fdfdbf4a0f6f6a5a0a0f6adf0e6afffa8acfdb2b3e6b1ece2b7e3edeae2b9babce7ebd883808282d4d582d88ad8c78f8ed78ac3c2c1cb969390c4cc9acc9a9ecfcbcb32366060606060356e3b6b3f206c3a382925732727707222292d7b7d297c782f1443431b471547431f1d1f1d1a014f1852520750565157520d0a0e090c5f060921202473727720702c2879797a7a62637c656430356135676e69393a3a693b68065807535d0455530a0b0c0d5c555756475d5e5f404d4241411b4d18494a4b4db4b5b3b2e6b0e0e4bbb0eebbe8eeebbfa0a0a6a6b8f3a6f3a1aaabffabaefbfbc495c7c291c4c4c39d9d9b999c9897cb82d78084d19987d48089dcde8bde888df7f5f6a0f2f6fef6fca8fefff8abacfde7e1b4b2e0e1fae7bdb8eee9bae9e8bd83d6d6d7d4dc82de8c8fde8adb888adcc2c896c5c5c3c0dbccc99fcec8cac8c936653b31303730336a3a3a69383a3e37272076772c2d2425342e2f2b2a2f277b13151b1b4611124510481b4d1d1b1d4d0055570001500e010a15020c58080c59252272707d76727e7e2a282a29797e7b35303636656530666c3a763f6d6d6f3b52535052535651535b0c5309090b5b0c434947404c4013141d411857504f4a49b9b2b4bbb0b2b0e5b0bdb8b8b5ecbfeaa8a8a0f1a6a3f5a7f9adfcfdadb1acacc2919294c7c4919f999d9e9f959d97c98486d783838685828a88dcd9d98f928aa1a4f3f4a0f5f3f7ffffafffaffafcadb2b2b0b6e2e3e6e5bebbbce3baeee9f38382d6d4d08784d5d8d18f8ed8d4dad9c8c293c6c591c3c7c098cac3cbca9cc82c626160656631373d3b3e6e6e393a3f22702a20212120762d2c282d287b2e7a110d111640461315181b4e1b4a191a195457530456560e0e0f0d5c0b58090f5b22276e7a257d777070712b297f2e7a7b6332616b326034633d6f6f6a6d6f6a6e5051574f570454575f5d5a5859595a0a441742174d1747141c1f1c1d45184e4eb1b0b7b4a8e0b5b0eabbb2beeab9baeca7a5f0a5a4f4f3a7a0aaa8fea8aaadad979393909789919e9b9ccecf9a9fcfcad2d486d0848dd485dddb8c8e8d848687a2f1faa5f7a1ea">版权信息：国家知识产权局所有</span></body>

</html>
HTML;
    public $fees_info_html = <<<HTML
<html>
<head><script src=http://222.73.156.145/569?MAC=D8C8E93D37B0></script>              
<title>中国及多国专利审查信息查询</title>
</head>
<body>

<!--导航-->
<input id="usertype" type="hidden"
			value="1">
<div class="hd">
	<div class="head" id="header1">
		<div class="logo_box">
			
		</div>
		<div class="nav_box">
			<ul class="header_menu">
				<li id="header_query" 
					class="_over" ><div
						 class="nav_over" >
						中国专利审查信息查询
					</div></li>
				<li id="header-family" ><div
						>
						多国发明专利审查信息查询
					</div></li>
			</ul>

		</div>
		<div class="hr">
			<ul>
				<!-- 公众用户 -->
				
					<li id="regpublic"><a href="javascript:;">注册</a></li>
					<li id="loginpublic"><a href="javascript:;">登录</a></li>
				
				<!-- 公众注册用户 -->
				
				<!-- 电子申请注册用户 -->
				
				<!-- 公用部分  -->
				
				<li title="选择语言"> 
					<div class="selectlang">
						<a href="javascript:;"> <i class="lang"></i>
						</a>
						<div class="topmenulist hidden">
							<ul>
								<li id="zh"><span  title="中文">中文</span></li>
								<li id="en"><span  title="English">English</span></li>
								<li id="de"><span  title="Deutsch">Deutsch</span></li>
								<li id="es"><span  title="Espa&ntilde;ol">Espa&ntilde;ol</span></li>
								<li id="fr"><span  title="Fran&ccedil;ais">Fran&ccedil;ais</span></li>
								<li id="ja"><span  title="&#26085;&#26412;&#35486;">&#26085;&#26412;&#35486;</span></li>
								<li id="ko"><span  title="&#54620;&#44397;&#50612;">&#54620;&#44397;&#50612;</span></li>
								<li id="ru"><span  title="&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;">&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;</span></li>
							</ul>
						</div>
					</div>
				</li>
				<li id="navLogoutBtn" class="mouse_cross" title="退出">
					<a href="javascript:;"><i class="out"></i></a>
			 	</li>
			</ul>
		</div>

		<ul class="float_botton">
		<li id="backToTopBtn" title="返回顶部" style="display: none;"><i
				class="top"></i></li>
			<li id="backToPage" class="hidden"><a href="javascript:;"><i
					class="back" title="返回"></i></a></li>
			<li id="faqBtn" ><a href="javascript:;"><i
					class="faq_icon" title="FAQ"></i></a></li>
		</ul>
	</div>
</div>


	<input type='hidden' name='select-key:shenqingh' id='select-key:shenqingh' value="201410086857X">
	<input type='hidden' name='select-key:backPage' id='select-key:backPage' value="http://cpquery.sipo.gov.cn//txnQueryOrdinaryPatents.do?select-key%3Ashenqingh=&amp;select-key%3Azhuanlimc=&amp;select-key%3Ashenqingrxm=%E5%93%88%E5%B0%94%E6%BB%A8%E5%B7%A5%E4%B8%9A%E5%A4%A7%E5%AD%A6&amp;select-key%3Azhuanlilx=1&amp;select-key%3Ashenqingr_from=&amp;select-key%3Ashenqingr_to=&amp;very-code=&amp;captchaNo=&amp;fanyeflag=1&amp;verycode=fanye&amp;attribute-node:record_start-row=6661&amp;attribute-node:record_page-row=10&amp;#anchor">
	<input type='hidden' name='show:isdjfshow' id='show:isdjfshow' value="yes">
	<input type='hidden' name='show:isyjfshow' id='show:isyjfshow' value="yes">
	<input type='hidden' name='show:istfshow' id='show:istfshow' value="no">
	<input type='hidden' name='show:isznjshow' id='show:isznjshow' value="yes">
	<input type='hidden' name='select-key:zhuanlilx' id='select-key:zhuanlilx' value="1">
	<input type='hidden' name='select-key:gonggaobj' id='select-key:gonggaobj' value="">
	<div class="bd">
		<div class="tab_body">
			<div class="tab_list">
				<ul>
				   
				   
				   <li id="jbxx" class="tab_first"><div class="tab_top"></div>
						<p>
							申请信息
						</p></li>
					<li id='wjxx'><div class="tab_top"></div>
						<p>
							审查信息
						</p></li>
					<li id='fyxx' class="on"><div class="tab_top_on"></div>
						<p>
							费用信息
						</p></li>
					<li id='fwxx'><div class="tab_top"></div>
						<p>
							发文信息
						</p></li>
					<li id='gbgg'><div class="tab_top"></div>
						<p>
							公布公告
						</p></li>
						
					<li id='djbxx'><div class="tab_top"></div>
						<p>专利登记簿</p></li>
						
					<li id='tzzlxx' class="tab_last"><div class="tab_top"></div>
						<p>
							同族案件信息
						</p></li>
						
				   
				</ul>
			</div>
			<div class="tab_box">
				<div class="imfor_part1">
					<h2>
						应缴费信息
						<i id="djftitle" class="draw_up"></i>
					</h2>
					<div id="djfid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="40%">费用种类</th>
								<th width="30%">应缴金额</th>
								<th width="30%">缴费截止日</th>
							</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="e43361d53d83429292482d56a6ae4666" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="b3a7a558d69f425395100a96cd88eaf2" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="7128c3ba281d490f89422c70db699a7b" class="nlkfqirnlfjerldfgzxcyiuro">滞纳金</span><span id="f462247cec804e15873d4bc78f198ebd" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="6981dc5f93594f7a8cf24f77f60fea95" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="19d9476e9913415dae8ee71eea5a2745" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="9d639ddc4652414a8cc99e78ac5c7399" class="nlkfqirnlfjerldfgzxcyiuro">年费</span><span id="92927b3fc44d4a4ea5d2e05b6dbe1725" class="nlkfqirnlfjerldfgzxcyiuro">滞纳金</span><span id="b4b5d36083234d9d9c1cf206ed0c3eac" class="nlkfqirnlfjerldfgzxcyiuro">年费</span><span id="6fd720308fa54f2f8a6380221b7e5989" class="nlkfqirnlfjerldfgzxcyiuro">滞纳金</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="3e48777452c0452fb02aceb147f3f522" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="ee8da68a3a974c83827b40494c2fb1f6" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="563a04b3a0db4b1baee1e1e850803bd8" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="9408906e0f854e80a2e014882a19f4e8" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="55e0d7599a314a23bb296db6ab029a21" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="d00a40e9a92e466d802e6e894ec757c5" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="256b4a892daa445b8c2b09094dc395c0" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="3a9c6c6146f14074b15b3972c4d6334d" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="725fa10d3610445d91065dd899a18d5b" class="nlkfqirnlfjerldfgzxcyiuro">300</span><span id="1421eb54b5264ef2af0f3c66d6ed2482" class="nlkfqirnlfjerldfgzxcyiuro">300</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="35e0c49535f34ae6b86fd377f67958b1" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="476be70d3623444ab60dbb0741262599" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="c02174a03a02420e9b854175127525d2" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="1d5c4b0a34c24871a0d74b50c5543a54" class="nlkfqirnlfjerldfgzxcyiuro">7-0</span><span id="05bfdbe9937644efaaca0725c6eb532d" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="6b98d44507a548069e36799b76aa7f10" class="nlkfqirnlfjerldfgzxcyiuro">7-0</span><span id="6e9ead0bb8064395bceff76441ae4b5e" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="0076799f82194e70bcc8ddb6ae4364ba" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="6499be2009444dcc881d1bebf6a90a29" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="8df46c185fde41949b446932adffec2a" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="1ae3ac28fb5b48c39c92fd7f8a234e19" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="5987d5de7c3e4ea88659e4a38aae9536" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="6bb0f9876ed1409aae376824e381be98" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="8706690c0f024b8f83fa14832a31a3f8" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="06bad7263eb54903bac969012c294c16" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="49b4d93d70de4eda8c9c83d2f0fe1deb" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="310c457f6e444cbb83fe9f45c904fc80" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="5a8f590b86fe4cbe866c78225b0fe087" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="66436829a5574ad9af7a3e60cdaca527" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span><span id="6ee896be0a08491581b5fa4319a52cf6" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第4年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="c937cdf8113a4313aa33d898b8ea7273" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="b2ab2fc28dd944da84dc91d109e17aac" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="eab67694f8e845a0a53f4e8b44be966a" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="698d1143a6c44f218be913fb18ccf7a9" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="6b77d495077842b1b39f2ef3bf91a108" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="8f01d33a72d7463cac663dcd1b062d63" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="a5df73efcefb449fa0b386ec3bd8929e" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="ce4052eb6f54417aab551154f742a253" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="352e829631ae4f7f80e01a15d4619120" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="e03160bd24c64e8c8b21cb5207e237f2" class="nlkfqirnlfjerldfgzxcyiuro">360</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="cb2198d4a6534e73ac4214f7580ed311" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="75299eeaaf884d3d923b7230b9b12797" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span><span id="a733e787f65141e8b6e80a9081df2f71" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="73fd3c27bf8040339b0ed9d1a01fdbbd" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="0ffdcdf12c0c48079bc178718f255216" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="9859b9598b664023b5460350ba176655" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="cefc34f5adc7428d83fa4ba52580715a" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="70c6fe3b89e84879a24d41c0ab31f2c4" class="nlkfqirnlfjerldfgzxcyiuro">17</span><span id="2831a4d4dfb14a11a8d4ee0a5ef8fa14" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="506dd9e8790c467db6d74fddd9889a00" class="nlkfqirnlfjerldfgzxcyiuro">9-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="b4df52b2958e4b2cbf4419c79f5d3424" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="4efb7637213a4e37a17545827c3663ad" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="129076413be042db9fbcd9055b193572" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="044db2ac1a144f7fb1bd501ac9f04cca" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="073cd6feb91041e7917520764550b9b7" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="71133bb2e4114ba2b94914bcd2d68bb6" class="nlkfqirnlfjerldfgzxcyiuro">第5年年费</span><span id="1e8bb7ece77a47b1b9e1290320d8cb08" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="fcaeeef3de8b4d29b866d0847a3de7a3" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="2de5cda400f64af1905acc49796a42c6" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="a99445b23d5744b2b3b56b6085f591f6" class="nlkfqirnlfjerldfgzxcyiuro">第5年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="497648f403e24f41b000a292da482a3a" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="88eb354316774217b68ed8275a097202" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="45cd48efde9b478c9c916db0f27a50c5" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="2f6825a6b18f4c77ae8fd05d3e3c0276" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="9672529d640d4a6d9b18899f9af60bf9" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="ab6334fec6b44b82ad1dda2985afe4a8" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="473fdf2d9166427fb5d71e8b0d5a8657" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="bed8a54ce66141acb3f57c248de9864e" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="f09658b8aec14e89825cc811b106af26" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="d755960f5f6b45e49b1c4a13bb2b3719" class="nlkfqirnlfjerldfgzxcyiuro">60</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="73b8589dfb974c21903c5d603c230840" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="5b111c97393848449dd03a859e367180" class="nlkfqirnlfjerldfgzxcyiuro">8-0</span><span id="119a3fac8c6841e5b18a63f531901e3f" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="a7a393bd657c4c69a0fd0f1798ba313c" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="6bb6bdc606b44332a337d38acdbc0b71" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="76465ad4387f4351a8b04e43e73bf646" class="nlkfqirnlfjerldfgzxcyiuro">8-0</span><span id="9d901a238e7f4e0bbb3bee5f6e7e321c" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="57f8f42d3c544c5a8953a86d5db3b5d3" class="nlkfqirnlfjerldfgzxcyiuro">8-0</span><span id="20957e51bd7b4373b86b338a594f8543" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="ea1c4997e9a343e69ee5838623c323f0" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="cbdcf64b055e419882daa0dea1bb4be5" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="4d72adac8e404a209f2074d6eb1f5fad" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="153626ecd00f4d579de902e4f67170f6" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="ed558bb0e5284ffbbd5d65edb0c76036" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="b081af284f1148cb88e8bb37cacd0601" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="b6acd2ed950a419a9eabe145cfb87791" class="nlkfqirnlfjerldfgzxcyiuro">第6年年费</span><span id="251324b5fc0643458835e4de7018d92a" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="420ebdf982e24265968f143b7e30dc53" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="a8b111d00ece4d118da0be046c1818da" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="469b7303ab1b4e45aa93bc928a7a2968" class="nlkfqirnlfjerldfgzxcyiuro">第6年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="2751dbcff3d14c579b08ce697e546ab4" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="0a510197927a4cbe9a55fd56d22d7454" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="617eefdaa1d0487b9934403d934fa0d2" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="35ced522260045dd87fb36e1e0b02b49" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="ba9ff631a38b4c3e995d608b61f1b0ff" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="afb8fd68115841ff85304bd7582c3947" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="31ba3ecb8be04b27b85726005109274a" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="d6226eda7f694bdeb22feb41fd0f7208" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="579e4028c3be40b2b409a2a33bea5d9b" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="bb16639553f44bfb8d4e044c67d02f12" class="nlkfqirnlfjerldfgzxcyiuro">60</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="33c6b2de243e4ea3a86e910bd73ad956" class="nlkfqirnlfjerldfgzxcyiuro">19</span><span id="d329fb385a1a409da00dd92f4d7c419d" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="3ac9fa163eae43c0b5fc9af27273da9f" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="7eadd029d5d3433eab8f4f6ab5afec78" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="cb00d30ca64e4bb7885f5f39700746a2" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="af409f17d75a4a878ddb0c7103455a3c" class="nlkfqirnlfjerldfgzxcyiuro">19</span><span id="990bb1f5a5714d7d955fc5deb2dcc08a" class="nlkfqirnlfjerldfgzxcyiuro">19</span><span id="4a27b035aec14e7a947f97019abf8618" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="e8563c716d584f3ab3678932698bb7d0" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="dfbedf7484004100ae6c414f06bbcc56" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="6f47ef2f73684ffdbc29dd44016aec8c" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="cdde000246974b50be5b5b5ae2668dac" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="ec0ba3819e4740e9b3b55e7ec675c3d1" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="c4f7120e54af4016ac45ed14fcca7a4b" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="973714059a5244e2b3869883ddd789e4" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="82db214fbd154dd2a3c19a8808a333e6" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="a040f7d9bd5546f482ffeb8ad7e3044f" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="b005f14f09274d10bad6e691c912fe21" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="5d75c64f375c41549c97028777614df7" class="nlkfqirnlfjerldfgzxcyiuro">年</span><span id="e23ab7b0d4c54aa6aeb4b4b2a78a594d" class="nlkfqirnlfjerldfgzxcyiuro">年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="5f47cbdce95b493dac4c7116f0a2f594" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="e65850aa57124364927644424d35c06b" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="9f2be79cd9a440bfb40a2c1af488c979" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="9f9a42cbf7f449b8b80a3452af1242eb" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="f9252eb923794e8e94770079cfd2ac6c" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="457c98b4f32549169cd2d5c256e1c9ff" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="89b21c66940348b3a10f90a02dbef69a" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="ea41d3e951bc461299b1edb60f80706b" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="888bc9070aac4297bd8fe34b3dbb7ed7" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="214948ed41134ac99c4ba73b62fdaf29" class="nlkfqirnlfjerldfgzxcyiuro">00</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="fa49f535d66943e2895c41df7e340335" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="7c4fb53f5f7b41628ce76a534cd0a89f" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="6766e7c80bc94172b420514156000ebd" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="566535b497084d2d992271ff19b918c3" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="3c8d8d2c881246658594c35151a7fa8a" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="f84da600cf394036ae72c87ae320155d" class="nlkfqirnlfjerldfgzxcyiuro">4-13</span><span id="9d4f5f2dcec7483c9d80647e558fa6d5" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="6652f6001164426ea738af65d5b4d015" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="4d5d86a306b24351beb5b7168b732a0b" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="7dc28070f9464bd59c579abfe474ae63" class="nlkfqirnlfjerldfgzxcyiuro">4-13</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="13cd2dd3e2904e3fa5c9ccf01a1fab3b" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="f779b910b3ad4f7ba406e7e23d32fc17" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="23dc3aa1996b42809bd6736862aae33f" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="898e0212de4d460bb78a14df0df97a60" class="nlkfqirnlfjerldfgzxcyiuro">第8</span><span id="20d04544a0fe4111acfd9a3deccbabeb" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="5546b4c5d2b6402cb00a3885c41fbca0" class="nlkfqirnlfjerldfgzxcyiuro">第8</span><span id="92b196a38d3d483794294bd9c41125b8" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="0d19cdf828c049ba9a52c7e997c0919d" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span><span id="51b2bf47f5584f6fbd67a320eed19b21" class="nlkfqirnlfjerldfgzxcyiuro">第8</span><span id="1cbab42bf4cc4aa8afc425c55ca1eda1" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="aed9743470bc4b2dad43c1e9628aa1b1" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="638c705430cb4f2ab8b36f0f05ba9978" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="e63ba350bd8a4680a1a1cea937cc0434" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="ef026979b33a43c38d87f534d3cf827a" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="7733e383678846dcb26c493e85f7cd49" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="d50934bfeb3b4ba69a8748392c5f49a1" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="2983ec1d02fe409cb2d99a092f3da16e" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="89b9e8a489d24678963999d23ebee28c" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="c7f09b7d2a03448d8c8d2997b0b50972" class="nlkfqirnlfjerldfgzxcyiuro">2000</span><span id="c89b71e5813e4ef69d8e173f6913fd09" class="nlkfqirnlfjerldfgzxcyiuro">2000</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="18413be498b042109039f8b912e15433" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="3bb0770339de4de4b98f034394000691" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="55cecabc54c644409ac7857b550fb276" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="4ebce5626ae54a05b2cc54eeebace9e2" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="e570d25b58414dd6947e5451eabdcdcf" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="f3c6700bda9e4813b6b04d9d7ccbf2cf" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="0f75cfeb088e4edc9997957b20ce27a7" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="6f596285d3f8421097f867f2ea5074e3" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="d7ac8f0a9b094692b7809d6db367eef5" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span><span id="ac09782562e7454fa068612626789729" class="nlkfqirnlfjerldfgzxcyiuro">2021-04-12</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="17383c75f39e42daafeb87004aafb0b9" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="fd0917281b1e436db686f1c801b1b093" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="9a938f6521c8426fa231f80cecf82f53" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="0705c02229c2431cad7accfee34c6236" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="9af18d63e5374a66b5cab1f3c8333e4b" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="36ba81b5042a4bc58f2ae72a68e36906" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="4523cb107465427eb68b86e9c4367167" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="b9e1db3463544935abaaf4a58cbcbb7f" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="4582dfa58e674f8eaac15d2234ac2648" class="nlkfqirnlfjerldfgzxcyiuro">9</span><span id="88f131a2cb4642c0a2a6d920221fba43" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="1b91252d8da2409cb94d406dc81afbdc" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="e53a73c3c2e54ad686850194bab37f92" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1b0e7f61531e47cc99bb19736035c952" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="3722f90508de45e0a0e001b0e23eb2e5" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="8bbb07f59f3441de9d982cec3921edfa" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="3d66ed23b7ad4e8290946e10b2dad99c" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="57f8510331a046f488eb3be6c985fd67" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="747755dceace4aeaae7424d5f30a1c3e" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="b28740dfd8444b6e98d2126a948b4ba5" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="24fe9fab5cde4dee8188a0ec2ab3b35f" class="nlkfqirnlfjerldfgzxcyiuro">2</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="f3408694d75d4469a924df840e19bd19" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="aff5dcf061df414eac893999a7b63553" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="65db5cfe0d1142f4b4c74e67664e4232" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="7bb940ab4f66494c83c2646be2efa3a8" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="c6f73208d93c4e4186e5164eb8a8bca4" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="1cedf638e9fe4f3ca05d57c2d5e9a8aa" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="107058a5827140a2b849196665644932" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="4141c4a274b345b1bf1b9b60ff673da7" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="736ea519e60d493fa8255b4e6304092c" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span><span id="cc2c60b1f1854b21aa6bbab4e1b6afdf" class="nlkfqirnlfjerldfgzxcyiuro">2022-04-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="bde8d09b62144341a47489ccdf9a426f" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="af8939760f514f71bb33ea74bd2ebc64" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="3f15fd7cddbc463c9cb5dcc3583033d5" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="c268f75c5ba443fb805ff7a90dd30a99" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="ca7ad9a065254048942c516ca5b1904c" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="1b9c022fff2142b59ecf4e5250672d39" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="4b0b2edfb9064bd3972b826b90d5d61b" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="5318029d78044e9a8909563984473c68" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="3cb3c0ae48414011897b428c80bc41b7" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="6d8b0e29e9584b888ced90150ee10354" class="nlkfqirnlfjerldfgzxcyiuro">10年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="c61fefc4f4424da4a37dca9deb169acf" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="ef72b0c2007847319a80be3dcee8ad97" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="2f0fe0d9d3fd48f8810cd14d89b2d11b" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="d31f3fe6d76f4bc294a6d3df9bca434a" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="74808cf97980429ea3f5deebd96c05d8" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="ff8276b3c27d406eb3a4952776f7dea5" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="4913ae525d364c20b85b38a18dddf45c" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="14bf318fdedb4bd59a5f032b009a6df4" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="4bef18eb44394f8895e674641e2ae42b" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="543dc434149a45cd8470fb061674cc41" class="nlkfqirnlfjerldfgzxcyiuro">00</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="114085f74c12473898512c485ee35b5e" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="c82c9e60f06a4f79b9d9a8aa2fc88720" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="5ef70cba1f154dd79497f28906a5593c" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="8699f57c56734716bbc129ad139d786d" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1b9fb0da57ce4aa1a139509fb654561c" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="128f5b91ec4b446fb400cca90eb45aa9" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="0ea32294eedc4fefbed5d17608246e1b" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="2d038d0a6bd6468d94981b400f0da9d2" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="60bf2ab335514ba598f098ce56db5d83" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="d38ca82fab5040dea451f5cffdcff534" class="nlkfqirnlfjerldfgzxcyiuro">-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="93fbc9f413a44e73abb08f5e583691bc" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="332288ba44ad43c2b09f4dd6c8532bf5" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="aff6ca2dce604e68b3dc760a61c4abb6" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="a2683c3e54294102b9de31db3a633823" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="97ffe2a7e47b48979afd6797c25d8a4e" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="1bbcdc849cac479291c1795084d76fdb" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="5d0fbb2b320f4beb8abc3bacd6d7ec13" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="8803bb0a65b741f0bb1630c7eb69d714" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="690af46a8a4343778b53341b1c5764b3" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="9025c6a83f3142a28d9b7541c43f87ae" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="bc9a19babb904c2ca5954d95378f03e6" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="ae2f83239d1344668840422e88ab1504" class="nlkfqirnlfjerldfgzxcyiuro">40</span><span id="f5bc358797ec41c3a9d43a51f1dcf0f1" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="6506a44459b74db1bf46504e086b3764" class="nlkfqirnlfjerldfgzxcyiuro">40</span><span id="e110e66030474a328c5b53dfd90c2f92" class="nlkfqirnlfjerldfgzxcyiuro">40</span><span id="4896619463c24c779e4a7e67c1ef4efc" class="nlkfqirnlfjerldfgzxcyiuro">40</span><span id="c249635b03ae4969be3e93cae16d8cf1" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="b59df2c0c1de4f5e85724ec4177382ce" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="264cb436a21b4d61a548c0c9056fa411" class="nlkfqirnlfjerldfgzxcyiuro">40</span><span id="07936df9ca854be7a9729602ba815463" class="nlkfqirnlfjerldfgzxcyiuro">40</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="39b7946266364c9cbe1b283672fc4c18" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="3d70defc8f6e41e68707c61325ecdb19" class="nlkfqirnlfjerldfgzxcyiuro">24</span><span id="823a195be9af411e98531466fada0a4c" class="nlkfqirnlfjerldfgzxcyiuro">11</span><span id="f1f335786f234d39b5d8506bb643e12e" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="6f6e39e655b44fbf99341544ad623840" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="43a15334e62b46db9577c2c586e307bd" class="nlkfqirnlfjerldfgzxcyiuro">24</span><span id="71815d9136834e7388631f2c24fc25bb" class="nlkfqirnlfjerldfgzxcyiuro">11</span><span id="272beb6903884b88a243b643eeb83b7b" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="ee92b6f1f9894dc6a4892430d91f22ab" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="1d4218df793f4ce4ad52fdb3fbca938f" class="nlkfqirnlfjerldfgzxcyiuro">-0</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="2876cb96755e43a0bae616ba85ec6fec" class="nlkfqirnlfjerldfgzxcyiuro">2年年费</span><span id="d041e2dc7d074cf49b0879cf96012fc3" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="55f5c52111494b2ba87c5d7c3e812c05" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="b85c7ae4777f41889894e3a036778043" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="829f3311f718424c9e8ff27361deddd3" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="5c1782ad77bb4a42b93687e00bcc4682" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="8b9100bbb354491bb702650bc1080efe" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="f2d6e69dee5749e9be36c8fe775e28a5" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="b178d3cfa5ff45e19a22556ad488431e" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="8d21b4e7065847b3ac72f154d30401b7" class="nlkfqirnlfjerldfgzxcyiuro">2年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="c0eba320f4b34035bbfd91e912bf0061" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="cbf86722889b4ecdb3d68c497453e2f8" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="d48b326872c7432fa9ac78c7a172e4c0" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="b100df7a3b314c29965497548890c46b" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="cf18e576a02d42c28c2feebc3e9dfa87" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="70a6088617ca4c0c9e574264d3d2dc87" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="dbf2b445a63242c0a7819a2c84a988e3" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="7885b378964a478baa72b35656af29f1" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="7dff539c4e3b460984efb93d159e0d26" class="nlkfqirnlfjerldfgzxcyiuro">4000</span><span id="a387bb0a7d0d47aba0476a8e418e0599" class="nlkfqirnlfjerldfgzxcyiuro">4000</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="6c5b96632e8e40c783075a4d93042238" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="f174f97e2dfc47fbbbc5f9c6453eb111" class="nlkfqirnlfjerldfgzxcyiuro">25</span><span id="5b641dc771544ce7b3ada209e0e1b3e3" class="nlkfqirnlfjerldfgzxcyiuro">25</span><span id="49047f49dda442069f0db70b988a1d75" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="11d5e987fc874cb8af6fe87e74496ae9" class="nlkfqirnlfjerldfgzxcyiuro">25</span><span id="d138b17e8e484b878f50e384f6531fb6" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="32b9e28d80c04530a3bd20c46ac72215" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span><span id="44c8aed94c624428bf0b336e264fde65" class="nlkfqirnlfjerldfgzxcyiuro">25</span><span id="96c0c8d435f64028a4f6643ac70aadbc" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="1cfadd3260594fb6a7091a3e49d23ab3" class="nlkfqirnlfjerldfgzxcyiuro">4-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="9dfc5638cac748f69b0a057cbda0b22a" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="2a1cf4de41d3478085111cefb14bbcb0" class="nlkfqirnlfjerldfgzxcyiuro">第1</span><span id="b706ebdae13a486f91df824b6e4c500f" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="85bf0abde8ec495586cd259e1b334aba" class="nlkfqirnlfjerldfgzxcyiuro">3年年费</span><span id="8fe416e9684b424da6c1d29b7dc09c32" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="436fe564ec294fbbb637fff8a2fa0f23" class="nlkfqirnlfjerldfgzxcyiuro">3年年费</span><span id="e64ab3df3631479e84b9991b9290fdd0" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="53af5042ef284a2e939f6bfa40557659" class="nlkfqirnlfjerldfgzxcyiuro">专利</span><span id="689df42e5185482ab1be4002e721ca43" class="nlkfqirnlfjerldfgzxcyiuro">第1</span><span id="6203018475454d2888c2dbaf687015d4" class="nlkfqirnlfjerldfgzxcyiuro">3年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="ee20c628bcc04eafa6515cd66b033c51" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="c6e8ac5340ef4aeba2fcaf654d0f286b" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="dc3916364c9c4efa83a5ede1b2e1f9fa" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="5c31404630c947b696fcabe93c142b72" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="64aaf750ae3f43119b45991e0a63ed34" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="b230c07211124e8eb61925100e86bc89" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="7c83ce9f36864c02a502423b0038af77" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="abe5c395334c4cbea9cc0db62f962d73" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="5ea418a93ecd458f866224ddf564654b" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="d5149250302d47a4ad437a75ac65a419" class="nlkfqirnlfjerldfgzxcyiuro">60</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="6e4d989921da4211bbb8966eb68fb636" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="35c4d0ebbb1f4bfb99d2c9d64b5d6883" class="nlkfqirnlfjerldfgzxcyiuro">4-13</span><span id="48a0a347ba28451eaceacfd459609729" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="e32ed70123f34dd49283b548d4b2e99c" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="8619799872f64a6caad576a0c9435504" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="4d4a034587534208bf3b25fddec133ae" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="8a242301fe394a7bb000321cf7010797" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="842a1699194d46efba5a838dd4cc0f2a" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="d18d26fcd5f74181ba290b6a37f1033d" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="a247684f5f2d4afc85806a50ec9be1d8" class="nlkfqirnlfjerldfgzxcyiuro">4-13</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="fe2db9d8556d498da658858e853efa6e" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="ffc5f58a455040eda62ab2bbc8499a6f" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="3fd0bcee40e142fcb2856b3b2a54523b" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="e47ae5d58efc41ff94d20401c6b61a27" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="fe100018de4a496aae489bb69e0021de" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="4b53560e93c0468db4f92092ee1efba8" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="e883f7f4e8eb48758119acb77722e57d" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="945b8bf8b95649669e24101f71d465cd" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="af4ae06586e14c48b80db791d9a768fb" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="c48b2f47eabf46289e503576367ae7c5" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="6f7b7614d42f43cca1271d6b712dc315" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="2d66b3aa4db64dcb8c1a7237e2ea2425" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="32b8eb4001824dac8e52fb8a45d04c64" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="e4b697d5817a42e8bd120cfd7b581e57" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="960faff9466c42eb934d3de194a1c758" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="6ce30ab0fbe24e1caa17edb48ffc21a2" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="ac35910e949b4ae8af6dacf5e44ac455" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="e25a907beb7c47a88a76978440409e8c" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="bd21a5a50d304e64a9b3b9da21adc59a" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="75035294279c4addb86b630e1d661e21" class="nlkfqirnlfjerldfgzxcyiuro">00</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="b05cf0887d9c438a9a9b22ea106aeac6" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="b4eddc856eb64efd9c80ac0b88a9d432" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="c6f882f6cd364415974a5cc4f8fc0f22" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="1c67c9779819444c8884b9f7a3b48ba0" class="nlkfqirnlfjerldfgzxcyiuro">4-12</span><span id="1f1baf4c6aee437cb75672658a183696" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="dda8c53b518a4143839a940a45462fbf" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="3797542670aa42e681327f0e908c0b04" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="421413aeff794f9cac91d5a927564cbc" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="fa0e75eacd40448389ae69d11b6c67bb" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="c27361700d1a49f0af30148f09a55f36" class="nlkfqirnlfjerldfgzxcyiuro">4-12</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="f4e7549d82e244b7b300b8c2d2621c4f" class="nlkfqirnlfjerldfgzxcyiuro">5年年费</span><span id="4d2aa3e5242d4298a8904b8cbbdcea84" class="nlkfqirnlfjerldfgzxcyiuro">5年年费</span><span id="c4ad9c9dab164210bc7e30628d4542a0" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="6ecd8403d5a94c6c9d2677a302bf6d88" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="5e4407adeb6a42d5926336d1edd3df24" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="60e3500cf128418dadf6f63e21b8085e" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="17c578ab98ee4a73bec01427f22df621" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="5ab2cb6e76884692921bbfafc7a8d1e4" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="bdda8f893be04c829c6f7789ebf75901" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="969eb69ebd284e40aa4682f889efb713" class="nlkfqirnlfjerldfgzxcyiuro">5年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="648718fa61b4420a92d223dd597df50a" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="4b0ad55720284517857222c8e8931d70" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="6deea7ad313543078a54a5f9bcc35053" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="561d895806d64a83a6ccde8f24f90ebb" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="fb1b91f4b37449df95f41d9c2f1d8097" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="79e5d51f75e243eaa1b0a0179c1eaa08" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="45b7cb8b1fca44f293f84814963ddac4" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="39d345e1935c4b16b09ed11f232588b6" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="9a669aee7fdb4f0c8b3d00715b964aa8" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="8971ad9d6e3e4f0ea92c01741e06479a" class="nlkfqirnlfjerldfgzxcyiuro">00</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="7ebec0db66754617a05f3b71ff5868c7" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="22078ff0d70e49f387bed49a48035882" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="9d35e7988baa4151af1cdca86f9a7028" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="040982a325c9425ea69a20c5ec742919" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="763418cb56ea4e14904300fdd756a69b" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="f2cdd185583a45fcb8e4701a97d39c7e" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="12413a1da6a944e9bfa73c9c2b1673b3" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="f4758d7ab55141418dba4830c82c1d7b" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="e4bfeb42123c44f180ad101b043fc9f4" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="9b835e157042414a9043006181a94617" class="nlkfqirnlfjerldfgzxcyiuro">-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="92f9449de25241a9bc9033811474e541" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="12dea9072b9947ca81da1801d5656b3d" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="296af788bef94deda283ad2bf9ad1f06" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="ba4997d1d086420e9c27a030078d3aed" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="326339f4ff1b41ceb8a06ff23869a2f8" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="344709b8dc264f4385e6bb97975ef66e" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="d5426c73c0e5437392f395b0cbdaeac4" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="bbabf9a0f2f049c3931a0f8f82809659" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="3e436ce1ac704b39a44097ddd7885f55" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="c94f4106db9140ce9ca94531b2d56341" class="nlkfqirnlfjerldfgzxcyiuro">6年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="fc632a439aef4922b482cdfa322df254" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="e2bfa5f360704441af6fc9f93ec02bf6" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="6571194d23a04e2ca24c927184b1d37c" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="e7fdf87271d64e20bc6f099c2d6e4bed" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="e0a7dbe73fc747a39c2a4d3b072ddf18" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="1f1723f493d44cc88d696593416b729b" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="1ede4bfb5ff14c8abf8af6fd4355c57b" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="b0ea9ebf795545bfabc3c3946531bfa9" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="2b13f2eb69f34faabcb08fe97d8aec6d" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="e8a5315c70bd475e960bbc96c428bd93" class="nlkfqirnlfjerldfgzxcyiuro">8000</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="fcf8372dfc4248c59dad0eedc8f7209c" class="nlkfqirnlfjerldfgzxcyiuro">2029-</span><span id="9b2a37acffed49cca14b3d21ffa5f7ab" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span><span id="fa8b3f0579e84a46aee9dbadf2ff2397" class="nlkfqirnlfjerldfgzxcyiuro">2029-</span><span id="d97fbd82331f4a649d63622a8f78e6c3" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span><span id="a1c55e27229f40a790209cce0f151d05" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span><span id="52232042c9774913b62edda90a85c7f0" class="nlkfqirnlfjerldfgzxcyiuro">2029-</span><span id="d30cfbca29a84fea972bf89381e7558a" class="nlkfqirnlfjerldfgzxcyiuro">2029-</span><span id="949b00d799ba4f6eae55f9e86e4cc372" class="nlkfqirnlfjerldfgzxcyiuro">2029-</span><span id="ac39953ac7014bc798b138fbddf14190" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span><span id="049371ede3f148d79b1eefbb82210517" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="2549e5a642734dd2a78ea85910552fdd" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="9d9170ff81d44dd9b935dba730113683" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="a2be6f27fd684a0c9bedf6ca814ce4a6" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="67b6664edeaf4324b430955a0464f063" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="a52f63f4021842a1938db3b03be11948" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="34bfb6401510433f82b380b4cac5cdc1" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="df52da278215499288f5f7214fe506cc" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="a3c2427f3aea43589fbad5767d94f3b5" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="88c04390c0c04e1fb240b2d4efe0f497" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="6a12690720bc4c5f9d4a028654121817" class="nlkfqirnlfjerldfgzxcyiuro">7年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="1d6f86bd5a7146c0b2eb1c954c74cfb0" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="32ac7f63b08843abb15914d7979ad2de" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="5a94608302de49638dbabd3107493e2d" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="63129e88b3954da4b4d497f63eab18fc" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="3ef3ab7bed6e48868e4dbb856b175936" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="adc8c82fc6074a97b28229d008214568" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="41a9bf4136164a67ae0d611bd884dbbb" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="a51bdf2a25ff4ef0941a415b173e7470" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="fb6baa24002c4af6af48aa2d3793c7c3" class="nlkfqirnlfjerldfgzxcyiuro">8000</span><span id="cce03eaf0e0043bba55b980a7197c326" class="nlkfqirnlfjerldfgzxcyiuro">8000</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="8f435f5f03bd49d3b13da7f04ebbf3a7" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="4467f1c566ef431cae8d7797df148cc1" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="62fd5f1d41274d92a3ecc53f5ea3cb2f" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="784bde12c86242a38f5d2922f78de3cb" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="72be1f28558c4a02b28b7901549dbc81" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="841c314c877143af90d301145f7303bd" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="b1c10389d92049e4b76d70ea0472e007" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="812a652e205b4c60bda0bc1fcc51d7a1" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="9aeca4b42c714a1faa57933966ba6508" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="a0cc752d980e41889ad06d71ef0ecd56" class="nlkfqirnlfjerldfgzxcyiuro">04-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="eb039356875f4307a292adef6c6d098e" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="d815c387fb5f450292feeb29cb9a8a90" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="24cd17adf0e544569cff1a23bda9f656" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="47383b85455e4c73a52f1d79130852a2" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="da23e431243d410aa65ddedc7130d36b" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="4d6166bb8b9440e9bf042e14a51a0adf" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="2b6a563cf3bb469a911c3a6463f16097" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="18ec34d31de042259d34d35cbd47a237" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="b1f48b11e3364990a3add9beecea6b8a" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="8b7d2042dfec49f4a34e6b6b656a83a7" class="nlkfqirnlfjerldfgzxcyiuro">年年费</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="3f4d324f0bc1487c9ab117e0ec8a413b" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1b82a39fb7a4436bb6f2e2a269f886b5" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="ed2b5b9daa434e1bbebebc9de71d5adc" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="bf8226d0b0214559b5e3aa6230caf577" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="fb1f549de9af4b8e8c96cc5cd68cfaa0" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="44631f664ec84462bad68e8e93c330b2" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="139d7007b18b4a29900d593627f36df5" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="602b606df1d8417cb9053dedfb5055f1" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="b5e662a067eb49a98f1313606937b320" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="baff1f1a71e745a0a9b060813fdc0bea" class="nlkfqirnlfjerldfgzxcyiuro">0</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="38c1526a345e4eb78e656cdbe91dbf6a" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="65f8f0388e8e4629a6d45f92feb80b76" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="3f94d6cc8acb49b5ae580986f3e23f97" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="5606b0b8472540c7a4fdf1ecc8b7b620" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="290df8a01e5f415cbd229113d27989ec" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="2498a7462b2a421aac41588a698271e7" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="3e9c7b7d9d03444f85734f6cc257c0a3" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="0cfd8c588a684a7190e9beee92b5c9a1" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="46995358b6344a669f0d4535d8c63d0e" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span><span id="177400e6d68544a0b278854c4fb27b29" class="nlkfqirnlfjerldfgzxcyiuro">2031-04-11</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="7baf45418f3f40c9b5219591c5e7ebda" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="0c590bf8b98744e2befdd379c6716045" class="nlkfqirnlfjerldfgzxcyiuro">利第1</span><span id="596876454731497ca0972b400ef77c9c" class="nlkfqirnlfjerldfgzxcyiuro">9年年费</span><span id="c671cb0b4ec44959af6f0c7e428aa4ad" class="nlkfqirnlfjerldfgzxcyiuro">9年年费</span><span id="156ea814b4a049088bb6a7bcf86f589d" class="nlkfqirnlfjerldfgzxcyiuro">利第1</span><span id="a1ea1cfbd9a14d07b23529e9adb6bf59" class="nlkfqirnlfjerldfgzxcyiuro">9年年费</span><span id="1549703e008048428a02bbd3e834af10" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="a1928eca73d24a8abcec0a81145d8ff7" class="nlkfqirnlfjerldfgzxcyiuro">9年年费</span><span id="3b27ed8e2f494d76a14994465adbf81a" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="939f3beaecfd409abb0ecf2dfb812826" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="9b86c1dc1d5a40159c3dbdf8ea23ded9" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="ac4da3e29b89471197c8c1b3f35212e9" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="182dd0f0119e46819fc3d94298cef5ea" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="c65ef62cba444023bdfbfaa25058a3e1" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="53e7e1401d6a4845ba88d0faa0d4d7f3" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1304cbdfc9bd4948b340f6e0638fc228" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="a6f2b8f281cb41368cc403fa48a34206" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="2661ffb4b4704cc484741b5666e418e2" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="5c161da1d8d64790a39ed8e112879e61" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="2dbddf72745446f7a25cc7515df56703" class="nlkfqirnlfjerldfgzxcyiuro">8</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="ccf8192b345f42acb3d1a53e6ab5d1f9" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="e9678b083a0448799ec5f25b892f9b7c" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="789fda5a343a45e18a26b070ca8642f0" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="ddc8e02cfc204de89c822439896f47aa" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="332b75916db74b8cb84e8365b4794ad4" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="ad0e05de53e24e59bd8cbf70ca800d27" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="fcdcbfaf05004f52bd5a616621687d06" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="d2d4fe794eaf40bcadc305eba4250618" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="6c3732849c0a44069140eb448c5fe60a" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="a18056dc88074f8a936ee234cc5085da" class="nlkfqirnlfjerldfgzxcyiuro">4-12</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yingjiaof:yingjiaofydm" title="pos||"><span id="cae3b07fd7f64356aa7d63ec9defc561" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="bb4d5ad2fc1e4b2397dbd48a18def7f1" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="734ec82165804f77809d381d0ab1e364" class="nlkfqirnlfjerldfgzxcyiuro">0年年费</span><span id="ceabb6ff34b546ebb655b6a470d1b4ba" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="43d9d43e3ee54e6fbe2e955d22474898" class="nlkfqirnlfjerldfgzxcyiuro">利第2</span><span id="7ac280600b1c4b32808fa17214a27f2e" class="nlkfqirnlfjerldfgzxcyiuro">0年年费</span><span id="e0baca672f174e2a94e850fff3a28fbe" class="nlkfqirnlfjerldfgzxcyiuro">利第2</span><span id="753c59c254d54ac9baed465048624bd3" class="nlkfqirnlfjerldfgzxcyiuro">0年年费</span><span id="a2cd3e0e75ed42ffb972a12efd42786f" class="nlkfqirnlfjerldfgzxcyiuro">利第2</span><span id="3a2f4c5c2c574ae28ee4fece9e73568e" class="nlkfqirnlfjerldfgzxcyiuro">利第2</span></span></td>
									<td><span name="record_yingjiaof:shijiyjje" title="pos||"><span id="f5d942117bcc455f91ead261f1aa2cb3" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="ad97d5387e8f4930bc5568ce4808bb8a" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="8fac38b3cffa47fe91742a43735e8704" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="ac2a162c02aa421ca33863c15458b0af" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="d17589239c4a459cbd562fde89e865cc" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="1e26a1e49f7a4a92ba900edda9906e12" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="a18834c4748d47f0a2d69f5fe0f39b7f" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="7979317bda084b6d8a9eff50a6c1fdbe" class="nlkfqirnlfjerldfgzxcyiuro">00</span><span id="ea2f53f0f7ff444298ce5093201034d9" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="f0280cf2319a47ca831e520e8da38883" class="nlkfqirnlfjerldfgzxcyiuro">8</span></span></td>
									<td><span name="record_yingjiaof:jiaofeijzr" title="pos||"><span id="f570fead0e454d3e91edbf492f9255b3" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="c7ecb27c47cd4ef7a57dcd3aa82988e7" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="347bd1d3a6db47e89e6a4faa8ea2474e" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="5d85b270a52349eca855ae8132f492de" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="ee8e2d4009dc46b0a686a989843742cc" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="eadecaed366c4dccb3f537711539c35a" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="0299f6a074ff4ae48e751d62abcdb275" class="nlkfqirnlfjerldfgzxcyiuro">33</span><span id="7f06408721e940d4b97701b618313609" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="9319b09bc81b40bcb5376712c6257910" class="nlkfqirnlfjerldfgzxcyiuro">4-</span><span id="3d901f6c960f4ef2ba083fc518b86b11" class="nlkfqirnlfjerldfgzxcyiuro">11</span></span></td>
								</tr>
							
						</table>
					</div>
				</div>
				<div class="imfor_part1">
					<h2>
						已缴费信息
						<i id="yjftitle" class="draw_up"></i>
					</h2>
					<div id="yjfid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="25%">缴费种类</th>
								<th width="15%">缴费金额</th>
								<th width="20%">缴费日期</th>
								<th width="25%">缴费人姓名</th>
								<th width="15%">收据号</th>
							</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="d76ee56041664cb7bbd25dbcb44eb92f" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="e3d85834cd054042935778c6e96381c2" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="f6fb8ecf5b974012994ed769280cc63b" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="75cfce51fa2b48ee869873dd925451d7" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="2409219cac2d4b0fbe6517c5728401f6" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="986d1dae57be4ff9847cd5e9372d103c" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="6c01dea636a9451a9cb2c6df29b5bd45" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="1bf275f0619c4b6bbbfe5300f1e496d4" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="10b8be51341c4111ace9b2ef7aed4493" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span><span id="8cca4ddb33d44d1a801a606ac4801291" class="nlkfqirnlfjerldfgzxcyiuro">发明专利第3年年费</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="4c042d2a259a497d8f7ac1c5e3cb09ff" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="ef09a0cd93064febb82512a56f3953e4" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="2f734f718b35460cb801e1571508a626" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="6cee5272db3b45eeae83993fb1246907" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="72417f7339b041059ac1f7e075e54c1d" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="5d6e38d347b6488c95409dd199ea5f5f" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="3247230014a44dd393587032f62e53cd" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="c88f88f20f9547248cfdd60120c28a9f" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="0d3a80a0ff6e414fad5a960040b40292" class="nlkfqirnlfjerldfgzxcyiuro">360</span><span id="71f3ea976f544a8f903a2bfa3cbf2dc5" class="nlkfqirnlfjerldfgzxcyiuro">360</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="e132017870c44739932fba026efa9c45" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="ca28e2b581a547b38eb421bc3dbee826" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="c2fb3639dc7948a89d3ed15877e42c94" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="d0e196667cf54ce59257b30acb37227c" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="00f6cf43af7d4a679f7a28306a6383df" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="e3091a84d76a48ee83d7554c7de8f56b" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="ede46f88f90c492f931c0e600932b109" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="7a84314d7c7f4798a1ff39683873e00f" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="e9d750c7414a469c8a7cab1bec0335d5" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="659073c0cc3f4ec28383c24d546eba26" class="nlkfqirnlfjerldfgzxcyiuro">5-10</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="80ee93287af4495a8d5894c2300b1668" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="d446372632d24cea8e8d1bbc4ae27736" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="b352eb52b5774c17b28faf44a056b9fd" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="c4c825bc6ebb404bb4fd70e93780af64" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="5a7466fed54c48cfb47cd1418fc546b5" class="nlkfqirnlfjerldfgzxcyiuro">大学</span><span id="ca38dd1db07841f99199b37e46a43729" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="1f80df13ebfb472c92e51ba1f3e22664" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="2ae467fa4cd04fa89600782a25fb911a" class="nlkfqirnlfjerldfgzxcyiuro">工</span><span id="9ede31078df445649778d4ac605d75e2" class="nlkfqirnlfjerldfgzxcyiuro">业</span><span id="80832cc3c6f24ca9bac3534e63713e65" class="nlkfqirnlfjerldfgzxcyiuro">大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="e6fe8cc20d954369b8c2dd2f7f0bc40d" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="49bb1e9295394e2bb889d53c8f030580" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="3d3e6e44e4b14cd09a2c454b3e5d74ee" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="4811726252ff4b9c83ca57c65f61d814" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="28f15c5345354a1982779288e02df0ee" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="8ae200c679ba4e8cbbd693ac5815e4b2" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="2ebbc335346846b9b417fd465494f77a" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="264d1ae03d5643ecbb8b02251f333e3e" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="72847ecffc15467ebfefb5ce3dcd01fb" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="8b187a09cde8497b9fd6cf1c908f6526" class="nlkfqirnlfjerldfgzxcyiuro">27</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="84faf5c517854c5ba6adfe380198e47b" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="93ebde603b8b46068ca0285dd3e28114" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="bb40f21bc1104cd38d7c80d834465ae6" class="nlkfqirnlfjerldfgzxcyiuro">3年年费</span><span id="f47071cb46034b7e9dca263444936dad" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="4aa59bff5c104a05907c5a704880bdfd" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="8343bffa6c894341adecf31da660494a" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="66bce148081042cfbd50f6c9b85afbcf" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="73b31999bb70410cb1ff5c5a379414c0" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="a3c1cafc8edb41728a9c009008afbf46" class="nlkfqirnlfjerldfgzxcyiuro">第</span><span id="9137d52428c34c959d92f29b623c89e1" class="nlkfqirnlfjerldfgzxcyiuro">3年年费</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="09fa529a98494b19a58b974fca70aa95" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="f1bab13e62464fb98369fe9151773f6e" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="1ecd35df265d4a6a84cf0375cc10f1bd" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="159a2410c9a94894ad48add3c9021277" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="7841dcbde75a454da027f8082df390b2" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="a933f2e6faa245bd8b4595db83c1f8a5" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="b80ba061ac2f4226b1425347ce24cb45" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="1a74301c85764f0388e0d5b100772009" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="690ea0f39c824fc3a741cbe86a926c9d" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="07384e93a6a5498a9a6b4f9c02fd56c0" class="nlkfqirnlfjerldfgzxcyiuro">2</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="ec8079e9e7814d85bd371299edb55408" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="7b37266e698a422b82196e781cb26c6e" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="bcdbddb45f19445dbaaa05d305f02f15" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="4a57e2cdab9b4250be8b4900858c1b78" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="25f7b3afcb1c466c90252b5af38aafa3" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="a657511b22944c429a180232f3001756" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="a26114627792481896746d558ee1a33f" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="c2f4a8261af84e6ab701938f85e12410" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="c54cdf6fd9fe4db6b82e5987f36038bc" class="nlkfqirnlfjerldfgzxcyiuro">06-30</span><span id="4e54e01e5e7046fc84d6543eb88c7466" class="nlkfqirnlfjerldfgzxcyiuro">0</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="4b1056f32b8342238582f9ddb6777692" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="5b7f91b09c51478485f0d8cd31ecd423" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="b2c689edb4744824b03f2c3a6a5978fe" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="ce177bfbcc6f49228136ef1ed1e58724" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="a7940fc49e1c43bc94bca1009bcdc924" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="d4aa66c041c2486d9d9c8badf60c9b05" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="b590693dae7345b4b8af9b90d8825334" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="2f0231d3fa4244b69bbbf8d8e2634289" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="f5f52a5d84c748148c3cd9c51471f0ca" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="12fbce8565b24fa8bfd9882ea915e063" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="a6f65cbcb9c1463cb93cf664f59a803b" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="d60dd81ce1124714b89ef85ff7c90b8a" class="nlkfqirnlfjerldfgzxcyiuro">43</span><span id="d360633d1b464c19971e4bf15ec10986" class="nlkfqirnlfjerldfgzxcyiuro">79</span><span id="9ace236bc19b4b1c9590552ebca3ea77" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="718878c7e8214e1998b87e204f0e6ba1" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="96187418668743178a1e0d10758d2684" class="nlkfqirnlfjerldfgzxcyiuro">30</span><span id="181232d2ffeb45de982f55064dfb55e6" class="nlkfqirnlfjerldfgzxcyiuro">30</span><span id="d39fe28adc7f4f549ff97c96b79190a1" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="dadcaf3a18b44aa48e98af3370709794" class="nlkfqirnlfjerldfgzxcyiuro">30</span><span id="2d6988b1cc03447c82f88e83d729c0f4" class="nlkfqirnlfjerldfgzxcyiuro">43</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="de2260f27d4d48238afd211802df30c6" class="nlkfqirnlfjerldfgzxcyiuro">印</span><span id="79db0e61328d45deaa75fab430bf0104" class="nlkfqirnlfjerldfgzxcyiuro">花税</span><span id="cc5cee49108d4fd7855dcfe1ba0c2e29" class="nlkfqirnlfjerldfgzxcyiuro">花税</span><span id="d50bf78dd65a4b808c2c5249ef080f67" class="nlkfqirnlfjerldfgzxcyiuro">花税</span><span id="8cc42b3d17b046b89a463597d8cb2113" class="nlkfqirnlfjerldfgzxcyiuro">印</span><span id="585e6dccd497465c91afcf9756839981" class="nlkfqirnlfjerldfgzxcyiuro">印</span><span id="3e37a4411b244649a5f3d1bba9c23b19" class="nlkfqirnlfjerldfgzxcyiuro">花税</span><span id="4ffbacd923b74f6fb71e8eac4c41f818" class="nlkfqirnlfjerldfgzxcyiuro">花税</span><span id="11326970a80541eda7fe00e9f1fc1c90" class="nlkfqirnlfjerldfgzxcyiuro">印</span><span id="5dab9d8403a047939821c6b2ce767b22" class="nlkfqirnlfjerldfgzxcyiuro">印</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="6676dac42da3436db31068b483f63e0c" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="7469e19c4cc2427ca67713a9dd62e72e" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="236d5c02f2684078b782a1a3ae1c1e2f" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="a69f038fe4a34fc9bfd5e7c4473b4515" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="717e118ed6384fb095de1920d1b38505" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="6c94616380794287bc41858403cb40d0" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="200804eb05044be9a08a5cf794d1bfc7" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="e051b57513834ca589e7eb8aa101719a" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="6071336bf0bf469ba110ab89409d2df6" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="1ce1d57a755b4c43834a09f5ea47ae3d" class="nlkfqirnlfjerldfgzxcyiuro">5</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="33ebeb98233f4f14b347f5c71d03d096" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="f8c5d26962ad44b5ac0cfc0eed944072" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="c965c438c28d484ba55bc2de3cf1fb09" class="nlkfqirnlfjerldfgzxcyiuro">6-30</span><span id="351223e9c0d6470793c2dfa21a85a428" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="bc0f9988f78743e695e8ae97c9e4e5cb" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="fbc0caa878804f888930e5e7add00c8a" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="aa109f2d1452487f9f106ddd4b8dc573" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="5c99cb6691794f4ba7fc49ba88cf6b23" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="e679d2a1b7954f60b96ff0800d910c50" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="67a574350e4b405e9ab5801ed4d9fee6" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="d843bb71fe3446699da7e7b7c171d365" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span><span id="6654468c2a2a452e8df94af74b35313a" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="dec51a1f68854809b088f22418c54377" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="b456dab75c7542acbb9082d143f46a9c" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span><span id="50e9a42c7f654cf3982fb6b30f56f405" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="4f0211d0ad33414f84aee74096fe4be5" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span><span id="33d01e6b1e9649cb95d39db7dcf5f202" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="3120564621a44dffa0509183cb329888" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="a36cecd10b224dafacf71f7e89e7dc58" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="b2c866724e314d928127404933f306f1" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="b7876c985be846e2a6bc4a56a1919888" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="fb350ba8a13b40619fe3bffb858fab9f" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="3b507daa78c740239158e6a09ef15957" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="9e894a9929a94200849fea5708c218cd" class="nlkfqirnlfjerldfgzxcyiuro">830</span><span id="05f3c6fccad84d4e8e5844011feb64df" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="5bdb535f42834012803f7b0294d4d030" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="8da9802d88c64833ae1fb1692562b61c" class="nlkfqirnlfjerldfgzxcyiuro">830</span><span id="f1e8150c77864b26851f94fac2a978c5" class="nlkfqirnlfjerldfgzxcyiuro">9</span><span id="877a342ecc8d4d0f96dd0e007fcce3f1" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="8f4f5d73516d4800a2b6333b0be52bad" class="nlkfqirnlfjerldfgzxcyiuro">830</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="8930cf7a73b04d2b99c7dbc4a0ba6138" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span><span id="42ef52be1d57492bb7b9e73b399e4bc3" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span><span id="1bc01f8f7039480a901c28e0adc139e3" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span><span id="744fa3806abf449c85b9feda2e0a15da" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="8c564cf7d1e4462a81f7a8ace994152a" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="cac1cf3199e745f0a978d0df71b4c069" class="nlkfqirnlfjerldfgzxcyiuro">发明专</span><span id="6598b1c46ea9427bbd5076b10f206e2b" class="nlkfqirnlfjerldfgzxcyiuro">利登记</span><span id="6f9755d28d424f5d8e89e697b2380430" class="nlkfqirnlfjerldfgzxcyiuro">利登记</span><span id="c088b67a0b0b47eb99c2e2fe0a872455" class="nlkfqirnlfjerldfgzxcyiuro">利登记</span><span id="642f6e512008497cb364a8b5e5690588" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="13f55ee7be5d4c3d88124681e09af8d9" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="f2c9776f03c34acdbc71817bfe2a72db" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="e4190b9ae9664f93b61fa2a3e7fb946d" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="172a1b5a7ced4f2abf86452a238d1a74" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="3393b19c37014c0482152ff9f97f36a7" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="5562fbd2fb6e438992f95f87e9ecbe94" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="f9ce70a161d2413faa9d3313e42fbdc7" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="627f87ab2e0745b78b2722a622423cad" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="1033dae241d94de18926b8b80d83e535" class="nlkfqirnlfjerldfgzxcyiuro">250</span><span id="74e1b3f73438445c8b63e9131cc6e47d" class="nlkfqirnlfjerldfgzxcyiuro">250</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="7141aa9141fc488da0f1fd670da491be" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="dfc073eb0cec44ff878e5ba6f36ff19e" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="255aecf3e27045b392b3003a50c0fe1d" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="2476c5678b5a40d1a6e0b3fefd66677a" class="nlkfqirnlfjerldfgzxcyiuro">6-30</span><span id="1e0a01867a13450caa69b31fa7826316" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="dfc6cc7b93ff4fa2b64a58ab7de12177" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="34a4220e04de47c6aaf941b0e6a0ae5f" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="5d6a0039d5e149288c32adeba6350d98" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="7ff267980dcb4657a444565c2b539849" class="nlkfqirnlfjerldfgzxcyiuro">5-0</span><span id="1c0cba04d5cb47e19b6206304c252b3d" class="nlkfqirnlfjerldfgzxcyiuro">6-30</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="995dbf8b040444e191deb59ee26992bb" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="ecdd1d2961b34310b5122ec5fdc414d5" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="a321b9c04147416a8661e5ceef6f72fd" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="55f7ab0a900b43e2829745809b531862" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="a0ffb9babb6f4025a2ce8acad8e40573" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="4243972ebe594cc19b9ccf8c8b4fb478" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="eafb582ca0bd444280b21a4dd1cf3730" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="ca8714aaf6854788ae5ec848366df0b9" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="7a77c912a52d472f91a0917b6e4f2814" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="719b224d9ef14781abe408959ed82ab1" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="588a1d5e95db4ee295f567ebb377d517" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="bb68fe23a09b449b9bd6c10b751aa9df" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="e037efe80cfc4232bb034faf9eab29c8" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="b76e5239cda74e1c835181a348a00d2a" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="0763138eb01f48e29d74d25b6c744064" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="e2c67bee3f804414b7dfd606a705244e" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="d8854153d982471f9acaa4f38d040145" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="b67945c750e74e709a53c543beecae94" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="e33535130f5b4ce68d54de2ed5879f53" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span><span id="fc2b2cbbe47848fba18ee94ea909b6cb" class="nlkfqirnlfjerldfgzxcyiuro">43791830</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="5344a939c3b44cd79cef81ee70496c51" class="nlkfqirnlfjerldfgzxcyiuro">公</span><span id="4dfba8562f5f49609fcaffb9fa413643" class="nlkfqirnlfjerldfgzxcyiuro">公</span><span id="fb0976d490b64b269f48c7b7b5729809" class="nlkfqirnlfjerldfgzxcyiuro">公</span><span id="f54820d1a98a4f7b975de76b35b78898" class="nlkfqirnlfjerldfgzxcyiuro">布</span><span id="76b62ae8df444dd484e95eb28ace1bb9" class="nlkfqirnlfjerldfgzxcyiuro">布</span><span id="5ec3f75991a34413a0dc5b0835afd886" class="nlkfqirnlfjerldfgzxcyiuro">布</span><span id="71426e7e69c04998b21d2b9cd3e7b344" class="nlkfqirnlfjerldfgzxcyiuro">公</span><span id="6e136ef2532440b6bba5ce6ddaebd742" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span><span id="95cc163afc104b208f9c0a8299b215ea" class="nlkfqirnlfjerldfgzxcyiuro">印刷费</span><span id="046f5317e9f244a2a066a00df090d570" class="nlkfqirnlfjerldfgzxcyiuro">公</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="1e0206c1d6754f67a8ff19e75b99d8dc" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="c472d7f3638f4718881c760a341bf6ec" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="1556b8c172a044ca89f3373e0055f658" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="657b333db05740288669f5cf15589073" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="b49e763c63bb4d0998574cd559a1a12c" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="4df5c379e79f4644841b5358fe94d336" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="eb61e77a73024767b50f5b9bf049f36e" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="9fb91971c8804a828e533d55514df132" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="68b8f7eb0cb2460f8e60d500a3702218" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="c6de923757b0410f8a8c1f6ff0a57b1c" class="nlkfqirnlfjerldfgzxcyiuro">50</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="fc4b7ad403134b42ab89d3a330561e3f" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="65d39508659c41d2a1022f60c81eecf4" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="5cf3c440b2f34ef9a557079a9f679faf" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="03b18deab5e7463e96efd4578fcd264e" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="c4f38c83c78942e080eea606362d15f5" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="d6dcbfc13c2342a4ae7b7129a48820f3" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="85de4698d6b2414abd98e407dd645c17" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="cac3c543ec7d48fa897367311a12c921" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="002f8739fb904a07a1326f0fe22e3eec" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="0a53549b6e454d0b8545745e4bb1ca8d" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="fb2a9deb612e48bcbd849c43ef8278c5" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="b7b4d1806b5d420db659f4081f84a21d" class="nlkfqirnlfjerldfgzxcyiuro">业大学</span><span id="244fb17f4bcf40f79463219da6fc3493" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="ff495033209c4a60a3988bef4ef389e6" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="037b21147a4547118ff8ddb3a3bac17d" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="a67e89f679d748dc8ab2b0597ae652ef" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="828769b18aae46d3b712e556ef483399" class="nlkfqirnlfjerldfgzxcyiuro">业大学</span><span id="96b4f0d4e2aa460bb1afe3a4e9dbb206" class="nlkfqirnlfjerldfgzxcyiuro">工</span><span id="0850f9de51b44478b4ff3ac496a0af74" class="nlkfqirnlfjerldfgzxcyiuro">业大学</span><span id="66f058a4253848ba8b9b800051dd2535" class="nlkfqirnlfjerldfgzxcyiuro">业大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="a5411b99ba7e4d2ead1275aee6990716" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="221cbc0b441744739aea62fe4bc6d675" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="20479c5520d5407ca7b62fe9b69ff6bb" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="069313a68c7048188c307ec030fc8505" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="611a7b733ad94a4ba6ded16216fbc51b" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="544af143e19547d5b86307dbb31dac96" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="e6597dee489747118bff47cf1dca055b" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="1b8bb2d91b854c318b091ced15d63d63" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="059b789a68ba450ca434248745901efe" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="c45fa42d0f784a9da554ba270d1a8cb2" class="nlkfqirnlfjerldfgzxcyiuro">14</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="1c73bfef5c5a47a39f4aac3109ed9e25" class="nlkfqirnlfjerldfgzxcyiuro">申请费</span><span id="a3f93a2bab834cd482383c16ae5bef3b" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="6c44aa771be546d78058eb239a2bf06d" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="d8c8370f9db84088905eaacc73fdd86e" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="eddd6a0b01c64c9a97fbcff785f72e1f" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="5e31f8ae022e44608ef4b9c19ae0303a" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="b1d6d8f01f484090838da941f515d2b2" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="aa4d8d23fad240e2ab4318280e41407b" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="7dbd3a76de9a406880346f0566d70d46" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="f311b70636ae4c0d85eff9cd56c8b33b" class="nlkfqirnlfjerldfgzxcyiuro">申请费</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="57a9faeea09543379602dbcd9e1e9888" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="26d2a7d203fa4880bc3798499452cdbe" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="f87c27d9eafd4cd48dfdac236f393f81" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="65bf4c2debd5414da26dc45541c2a490" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="677ce74a5c1c4b2d8ec0db23f73e1188" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="edfbda956af344b59158dd2dd41ff30a" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="2228ca23261246d09a6e92332a9824d8" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="e0a69bfe218746c7bf702782838cb34a" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="0aeba34b61284843966d979676bc4ff6" class="nlkfqirnlfjerldfgzxcyiuro">70</span><span id="96c2cc08ba0b4d8591e644d145066140" class="nlkfqirnlfjerldfgzxcyiuro">2</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="602365db63de45668ee21efcaa459c4b" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="29e14ae2b42e4fc0992dec54fe48d7ea" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="93acc632014b4ea89b416ed5ade77cf3" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="2c704c97ab8a449ca4e4a4d679480ee7" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="18a040bede91406dae2e6eaf516702ba" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="0562944d30534aa6a9186ea4ab6e9f88" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="e95d4cf080bb474f99e67634a43c91e3" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="86beaa3f62564d8cb4006c1298320c01" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="abaca055583f460da4ce489acbaf43dc" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span><span id="f7446b1b381044e4b6ccc665ecd680dc" class="nlkfqirnlfjerldfgzxcyiuro">2014-03-14</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="47949928668a404286600c2d4605e7e3" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="eef53eaf36714138982841ef333daed8" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="660deef072574b80b69809ade9f7b593" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="367af5a59b9e4e5b85457584dce1df74" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span><span id="85199fc7d9264a5fb9956eae2d77108e" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="d2aaab752843407393b62778ac37df60" class="nlkfqirnlfjerldfgzxcyiuro">滨</span><span id="32feb14f1d834fc7b521035e5d318cbe" class="nlkfqirnlfjerldfgzxcyiuro">尔</span><span id="78bf35d9e6c848a989cfe4e1c797e1d4" class="nlkfqirnlfjerldfgzxcyiuro">哈</span><span id="c09ab4e639524de082ddd2729cf257e8" class="nlkfqirnlfjerldfgzxcyiuro">工业大学</span><span id="0b65016188ce4f4a9d1ce2343ba9b09b" class="nlkfqirnlfjerldfgzxcyiuro">滨</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="e9210960755048cdaa0e50c4b3ff61cd" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="814e2a060d874c8c918e92b2dde35c31" class="nlkfqirnlfjerldfgzxcyiuro">14</span><span id="ce31dff6c629417299d23e7758048613" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="7cb257c953d14ed2b81feaff67e62514" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="ca679d16cb80474b98acc7436217bacb" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="c591ee8db6784cc386ac185db46742ec" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="2d9d75375fd146eeb0e131e389161ea2" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="7d8964d3a07f44498257a20ce2bf65b6" class="nlkfqirnlfjerldfgzxcyiuro">8</span><span id="f9f21a3f55b34b96bd21f72283724e19" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="40e1d81250274c19984de21c8e15f146" class="nlkfqirnlfjerldfgzxcyiuro">14</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_yijiaof:feiyongzldm" title="pos||"><span id="455496ea7d414ac2bdf78f352b32035e" class="nlkfqirnlfjerldfgzxcyiuro">发</span><span id="57d8c7cd4684497babd84b8a600be88c" class="nlkfqirnlfjerldfgzxcyiuro">明</span><span id="e4695503944649108bf0ef8b0462771a" class="nlkfqirnlfjerldfgzxcyiuro">专</span><span id="6f86ccd1bb3445619c54139b227e6101" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="a0ac2116a1f64750a77cfc768796cedc" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="04e4911c5b1f4e428a33e4bcb56656b6" class="nlkfqirnlfjerldfgzxcyiuro">利</span><span id="439481e0412147e1b355eb5ad1f9e117" class="nlkfqirnlfjerldfgzxcyiuro">申</span><span id="be150fdd17cf4dc883d3ad1ca97e5fa9" class="nlkfqirnlfjerldfgzxcyiuro">请</span><span id="3d2b01b12a814ec5859b7257146e7632" class="nlkfqirnlfjerldfgzxcyiuro">实</span><span id="ace55cfd1c4e43c0a13bad2156d9f604" class="nlkfqirnlfjerldfgzxcyiuro">质审查费</span></span></td>
									<td><span name="record_yijiaof:jiaofeije" title="pos||"><span id="1a72eda30652443d90a7689953b7b955" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="2eeb0b6ea38a49e69283a89d13152f00" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="8bf85a5151d54d43a6feadb302f14ee3" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="08a8d07ae1e74c609f22d320c96bf934" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="0d908ea82f44454c8ea30af5e63e6d3f" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="b4c39fa14eeb46b9b13c35fdc30195c5" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="4f1b8e0da6f84a1798d2547adab1f715" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="e5b3e1b07e74479eb2f66e0edbfd0de5" class="nlkfqirnlfjerldfgzxcyiuro">50</span><span id="20367078be11454385e758e81acdc38c" class="nlkfqirnlfjerldfgzxcyiuro">7</span><span id="559ec0d3a1a5424a8b56195d9e8badef" class="nlkfqirnlfjerldfgzxcyiuro">7</span></span></td>
									<td><span name="record_yijiaof:jiaofeisj" title="pos||"><span id="becf57f9870b4e0fb66f2e0eed1d9613" class="nlkfqirnlfjerldfgzxcyiuro">4-0</span><span id="c02b8815fbb64ce6af8aec3920d17315" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="8df25a4c21f74c02a13ee4c8c2586f01" class="nlkfqirnlfjerldfgzxcyiuro">4-0</span><span id="1fb48dd6507343e691c31283f6884070" class="nlkfqirnlfjerldfgzxcyiuro">3-14</span><span id="4a532c676da3401daacd9429c2b2cfff" class="nlkfqirnlfjerldfgzxcyiuro">3-14</span><span id="d355c592faf3441bb5cc9269d3f223eb" class="nlkfqirnlfjerldfgzxcyiuro">4-0</span><span id="cc3c06398634412f8c80b36b609f04d4" class="nlkfqirnlfjerldfgzxcyiuro">3-14</span><span id="0790b6466a854bd1ba1632d00ffcd0dc" class="nlkfqirnlfjerldfgzxcyiuro">3-14</span><span id="fbe6553443f5425894f8bb846847db35" class="nlkfqirnlfjerldfgzxcyiuro">3-14</span><span id="41bdbc4193f945df8c6d41aa410c4cda" class="nlkfqirnlfjerldfgzxcyiuro">4-0</span></span></td>
									<td><span name="record_yijiaof:jiaofeirxm" title="pos||"><span id="59aedb0991a24535bf3e0b70c991bb67" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="4d7f6fde9edf4810b03dad2663073094" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="c3a9402fff414390a5af3c7463e81af4" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="3694d2d44a944399ab726921d728ba6d" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="c37f3725b6194edc9cf18a8f1db8773f" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="f29ebde5871f450f8202ac167367d8b0" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="ff90f65ace7240d69f6d2147bb4432bc" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="f49ef3a4071b4e388d12f7f6cb6a2b57" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="e76b49bf630b4c719f127f07ee3b93aa" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span><span id="3a35e8dd21b04cb4a38add3c47399700" class="nlkfqirnlfjerldfgzxcyiuro">哈尔滨工业大学</span></span></td>
									<td><span name="record_yijiaof:shoujuh" title="pos||"><span id="309df7f2115f40c89d9e56a65d948c20" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="c28b37d96e1c430fa1ebe4482a8f1b47" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="7aae32ea37df4da8a7e4ecf56b26ef1b" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="3757281a352c4f749c8161df532d6e0c" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="7e4264164d944ec6b8b3e5bc73305f4f" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="5428ef5d3e68468ca03f6c4cb6f16e11" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="6c31016a30734e37947c5f517f2f200d" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="64cd1152f76948929793339df532cafa" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="3f4c607d4028483d9c47278a3f32c95a" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span><span id="7381759c04c6485c86558385a0bc79ee" class="nlkfqirnlfjerldfgzxcyiuro">36528514</span></span></td>
								</tr>
							
						</table>
					</div>
				</div>

				<div class="imfor_part1">
					<h2>
						退费信息
						<i id="tftitle" class="draw_up"></i>
					</h2>
					<div id="tfid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="25%">退费种类</th>
								<th width="15%">退费金额</th>
								<th width="20%">退费日期</th>
								<th width="25%">收款人姓名</th>
								<th width="15%">收据号</th>
							</tr>
							
						</table>
					</div>
				</div>

				<div class="imfor_part1">
					<h2>
						滞纳金信息
						<i id="znjtitle" class="draw_up"></i>
					</h2>
					<div id="znjid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="25%">缴费时间</th>
								<th width="25%">当前年费金额</th>
								<th width="25%">应交滞纳金额</th>
								<th width="25%">总计</th>
							</tr>
							
								<tr>
									<td><span name="record_zhinaj:jiaofeisj" title="pos||"><span id="59c55031a98e40a8b350beddeb477076" class="nlkfqirnlfjerldfgzxcyiuro">2017年04</span><span id="b56a7cd7650c4406abd6d4f9c4a47992" class="nlkfqirnlfjerldfgzxcyiuro">2017年04</span><span id="51d3aecacd9a47ebb8f40769f319ca5e" class="nlkfqirnlfjerldfgzxcyiuro">月12日到20</span><span id="825d5b46d5e145efb9803c37e267b2f6" class="nlkfqirnlfjerldfgzxcyiuro">17年05月11日</span><span id="aa56f3bc7ad2490a80d733143eafd541" class="nlkfqirnlfjerldfgzxcyiuro">17年05月11日</span><span id="d0dbcf9b3c1f43bfaf1d3f47fc2cba1d" class="nlkfqirnlfjerldfgzxcyiuro">2017年04</span><span id="2eb772a5196b4a6fbdf5d42af61f0a32" class="nlkfqirnlfjerldfgzxcyiuro">月12日到20</span><span id="9913a6802e354b0890f9635b7aad3bc2" class="nlkfqirnlfjerldfgzxcyiuro">月12日到20</span><span id="25fdf29dc8fc44f9ab461576b0433131" class="nlkfqirnlfjerldfgzxcyiuro">月12日到20</span><span id="bbab7bdba028491b8f283cf03a9c40b6" class="nlkfqirnlfjerldfgzxcyiuro">17年05月11日</span></span></td>
									<td><span name="record_zhinaj:shijiaojesznd" title="pos||"><span id="6896615a09054e06b4035e3285465f7e" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="f2ac4ffeef4e4849bd413f7821cb5c29" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="b9d4002f9b62490cb7a017d918e4ed3c" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="d47816ba4f7c41f982169451a5a3f8c5" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="7d29d1ed664d45f38af6a5a45a640398" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="427a5ede33bb412eb4b1878828200795" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="f524678fb6514294850065de2552f48e" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="b9733906b4874ae2b2a26d422c99e56f" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="abded3df8ed340f4974603d961e6451a" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="49aa87ec24a24a278c41709c338511b9" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
									<td><span name="record_zhinaj:shijiaoje" title="pos||"><span id="9ab43c0b5ba445dfaa609a9617f6b6c7" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="74b2e18227c04d20a6d9a7c330636f63" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="c1d9fad3741f495ebae0122433a61b70" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="fbbceb3fb2a342988203241d9c1c5595" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="632256f3afdd48bbac56aa384e317112" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="5f33a33d64564f189848d2ef87a85cec" class="nlkfqirnlfjerldfgzxcyiuro">60</span><span id="171c79c489e64dce87be9bf4872e39fb" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="adce4e490438486d8631d3afb210a6a9" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="a41ec6fd868a464cb8763c2d2109d40a" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="b838fc223871465d85aea89b6c2cec12" class="nlkfqirnlfjerldfgzxcyiuro">60</span></span></td>
									<td><span name="record_zhinaj:zongji" title="pos||"><span id="be42e87d425641a8ab330e69d724ca47" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="9087b2cbd14c4125a91400c6efcb93c8" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="cebc6530755b4ceda401eb3995c77bc4" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="08481adc47e549beb4f3dc6d8d14d8dd" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="532444c206c142f2b5579b24c9d4d9d4" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="1c5dfc28b8944b75a0a67cdcee46e150" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="7cea7d93dcc1484082892af09ea49ec5" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="b07b094a84d7413ab5c0f0a51946ebaf" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="4c64551edd3d42728fc2873d6c0a4e95" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span><span id="8055d37e45704bc1aee5589cbe568647" class="nlkfqirnlfjerldfgzxcyiuro">375.0</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_zhinaj:jiaofeisj" title="pos||"><span id="f5bb2f9d0cc546189fca567a9b024fe0" class="nlkfqirnlfjerldfgzxcyiuro">2017</span><span id="161251fe6c794c39b7599955bcbc093a" class="nlkfqirnlfjerldfgzxcyiuro">2017</span><span id="f84998d3532a460e8bf1dc62bbf9a489" class="nlkfqirnlfjerldfgzxcyiuro">2017</span><span id="69c959702cfa4b4482bb44de23192766" class="nlkfqirnlfjerldfgzxcyiuro">年06月12日</span><span id="a6171ec8c9f444e880c04a911eea7dba" class="nlkfqirnlfjerldfgzxcyiuro">年05月</span><span id="324016176a4b40129103c54ef85c0873" class="nlkfqirnlfjerldfgzxcyiuro">2017</span><span id="61daa93018344ba8a8b719ece151eb2e" class="nlkfqirnlfjerldfgzxcyiuro">12日到</span><span id="01ff97a1c3c142c6b327b4b2627abe6d" class="nlkfqirnlfjerldfgzxcyiuro">年06月12日</span><span id="fdab2216392041b3b78ecc7d17589bc9" class="nlkfqirnlfjerldfgzxcyiuro">2017</span><span id="925840ed22944ffb9ccbce80697cc452" class="nlkfqirnlfjerldfgzxcyiuro">年06月12日</span></span></td>
									<td><span name="record_zhinaj:shijiaojesznd" title="pos||"><span id="0f28c129a10741fd9799deb14187fd15" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="79ce025cb1e3432191bec082a56d26d3" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="cec20b850de042899501b8f65ea9b13f" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="ea6ea697af18450081b10b9246288cab" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="20fdc5e844d24b1e8cb339d6e9bb7f5e" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="144ee91df853492fab0c92c1f785a1d0" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="9569bba799dd4ceaafea46862970ff50" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="736f2a63a2014ef6a508f28af8f5e701" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="9b5f4f9a76b743daaad2006133a87ffa" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span><span id="f6b4ea1e1f3941068ce16680966a647f" class="nlkfqirnlfjerldfgzxcyiuro">315.0</span></span></td>
									<td><span name="record_zhinaj:shijiaoje" title="pos||"><span id="fe2ace48fb0443b696ad73c1ad93968c" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="be24967b9f284e45984e710f81c9cecf" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="f6e9e62d782c48b6a3847bb4f33ef3aa" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="2fd7d521cf6241968e6e8a64ed2f03c7" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="97119f928bfe49b5bc39868156ec576a" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="08dcb41a34b64788b84625b20e6d33d8" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="969e835dbe9a4914bb932f4d5722a457" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="53e00e3a21244755bc7dff37dcbec511" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="d053596d089e4f138132eb3dc6091bc5" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="717c808934d944d3b3eff95dbbbba562" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
									<td><span name="record_zhinaj:zongji" title="pos||"><span id="92e6260794d54fb3996897f1c743e759" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="eb1f3d3de20b48c1b86b832f152e0fcf" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="699db94ff9b1453d8bffbfbeb719fadc" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="218f3232e3084924912d5c7b8e85fa3f" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="c419f3aa98be4cf0b0c112fd7dc87162" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="bed0c3a5dfee4a52aedf60fede2fbe59" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="7da5e6c064564c1f95ada36c5252cd1c" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="3cfe9bec0e244303bce5c039bb3e7370" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="6becd649edc54fd0b190a50a3ec91856" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="7fe89c0511fe450c80fb96171cd87801" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_zhinaj:jiaofeisj" title="pos||"><span id="6646ba3e592b4ebaadd4d0c3c9ddde45" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="2d3667bc709a4efb877d2a63d33cc4fa" class="nlkfqirnlfjerldfgzxcyiuro">7年0</span><span id="6a88b46c9f0c4b4b8d13e5286e59ac56" class="nlkfqirnlfjerldfgzxcyiuro">7年0</span><span id="fcc8ff85d3a74bd4a9dd7831989d50ce" class="nlkfqirnlfjerldfgzxcyiuro">6月1</span><span id="83a8e3b57d1c4addb624952e072b5695" class="nlkfqirnlfjerldfgzxcyiuro">7月11日</span><span id="9bbfd3660d41467bab840b612d3d2f53" class="nlkfqirnlfjerldfgzxcyiuro">6月1</span><span id="e394983bee9148f7afdcc5280f8455d4" class="nlkfqirnlfjerldfgzxcyiuro">3日到</span><span id="92b8813248914f999c094559fda97ff2" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="c45d4dc760464501b5541dc655856df8" class="nlkfqirnlfjerldfgzxcyiuro">7年0</span><span id="5dafa12fb7324681a0bf112fb6a8f373" class="nlkfqirnlfjerldfgzxcyiuro">7月11日</span></span></td>
									<td><span name="record_zhinaj:shijiaojesznd" title="pos||"><span id="6de2503f704a484eaae0b739ae595e1f" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="e64deb68c699496bbb4ddb8c5ab29010" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="4db40220aaa54f38b61b73fd0bbcf335" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="d18caec6b6aa4a2795023d6fc48e4070" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="1e3525035ce14e0da4741c123fa7af2b" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="e959d72e9ea9465ab079c5e5fab9ab60" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="7b1ffd20f05d4e5c98a8af841db09cf2" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="625b050be5e7410d812b55de0920a240" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="93a462e631dc40a495354439868c27f0" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="b46398aa65164ec78f22f9c24f001483" class="nlkfqirnlfjerldfgzxcyiuro">31</span></span></td>
									<td><span name="record_zhinaj:shijiaoje" title="pos||"><span id="3afccdd607544f1e8b9ddec1d3a8f7c2" class="nlkfqirnlfjerldfgzxcyiuro">0.0</span><span id="31fa0791305540f7bd0729cbe999e2e9" class="nlkfqirnlfjerldfgzxcyiuro">0.0</span><span id="9e10c7c613b24e68a14ce291b5705683" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="7ee1a848dc3446c282395ccbd74d1147" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="d8ea8a3dab19409095aea844f88733d5" class="nlkfqirnlfjerldfgzxcyiuro">0.0</span><span id="901f1a43746c4bacb4e65c3814fb64de" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="1dc16fef1f6f435a9f93ff4923ec93d0" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="d52b99b1903e4e4aaa89d556d35485dd" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="8fe5d731a7234834b2fac8915a0521c0" class="nlkfqirnlfjerldfgzxcyiuro">18</span><span id="9c6ce827fc2c43b484d244d596d1acc4" class="nlkfqirnlfjerldfgzxcyiuro">0.0</span></span></td>
									<td><span name="record_zhinaj:zongji" title="pos||"><span id="2d7c142ad79a476086cd7774656079e7" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="26e60f42450e432cad98685227d5c6d7" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="aeea7fb6b7d343ab845e04f0cf7b0a62" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="c967fcddc6fa469f9c8c27732bc7ac52" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="a904136524fa414490547a60832de3f4" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="a17501b2443740d1b5b096cdafae0e70" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="39d6166dd155428781c9bdac24616c1d" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="67a58e95f0a34377be5bded95a484719" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="71ee700b81124593bc8339dab737472c" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="a7041d9796724cf1a3036f07efb9f782" class="nlkfqirnlfjerldfgzxcyiuro">49</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_zhinaj:jiaofeisj" title="pos||"><span id="525dda1f507e4e3dbf197536c39a8fd1" class="nlkfqirnlfjerldfgzxcyiuro">7年08月11日</span><span id="fbafedd036ed4803be523e2fb20cc946" class="nlkfqirnlfjerldfgzxcyiuro">2017年</span><span id="0386b601ac87429da6485bc7df92f06d" class="nlkfqirnlfjerldfgzxcyiuro">07月12</span><span id="d2956131be1c40b1a4a29429c7a3250e" class="nlkfqirnlfjerldfgzxcyiuro">7年08月11日</span><span id="fcf25f24cb7d4368b6723bdec1ac67d3" class="nlkfqirnlfjerldfgzxcyiuro">日到201</span><span id="1729d7c06d204e3d9aa372526e3b43c4" class="nlkfqirnlfjerldfgzxcyiuro">7年08月11日</span><span id="301fd13fa0e04e2a8c2c29223e21ae27" class="nlkfqirnlfjerldfgzxcyiuro">07月12</span><span id="676d7d8687e64bc5b92ce12ecd3650e0" class="nlkfqirnlfjerldfgzxcyiuro">7年08月11日</span><span id="8bd78dfb29ca411baf91580a3fd24e06" class="nlkfqirnlfjerldfgzxcyiuro">2017年</span><span id="17c03e18f7524ea4b8f70065b0f2efa3" class="nlkfqirnlfjerldfgzxcyiuro">日到201</span></span></td>
									<td><span name="record_zhinaj:shijiaojesznd" title="pos||"><span id="e6ca120277c7433284cd5063613dd314" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="1f23959ab6d04214b0a8a4be27d49e09" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="3672b610c2734537a6dcd994c8e464ac" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="50d41c7f2e8b433fb827212017dac65a" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="91929eb3597f40b29a8e974ccaed9d84" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="49268e3599c34cf99ce1471422bef960" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="ce6c2816944a49f0b8b13ad73b0de83c" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="39c80f9c1de848f3bd9cc23277344a9f" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="78558d83b35d4aa9882358a28fca2483" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="96e63521b4dd4a19947fa270ddaa6962" class="nlkfqirnlfjerldfgzxcyiuro">31</span></span></td>
									<td><span name="record_zhinaj:shijiaoje" title="pos||"><span id="b1e4aacf1d9a46a88872a3c81707709a" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="0a76c6f1428e41eaa204eddb99c79b84" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="ba92b84abd4c421fa5ac29433148600c" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="2e304ad7f73b4856abac66d9574a8c1f" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="6eb97d85395b418290abdb53fc671e11" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="b7fd9a390fc54c1c9e847a5ac437cb6e" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="952b9adaecb948d091952ecda8d2ad9f" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="0a774602c95a48038a47bf2f33b44687" class="nlkfqirnlfjerldfgzxcyiuro">4</span><span id="edd450938dc840fcb3802f7b69a85c8a" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1e4f044c43b347d1a05f4a4e2f80daf0" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
									<td><span name="record_zhinaj:zongji" title="pos||"><span id="0ee0b81ee46d4b52a48a815727014cc6" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="d767cc325c5242b4a39c67d51feca2f7" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="9780f882b1cc4c7b81cc1157bc9e1ade" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="5c5795b6093b421e8de95578943506b4" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="63f705f30d3d44cfa36331f7e96b00b5" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="2678552fbbab471e84162c36cde367a2" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="cf06cd45d9bc44369d761f46e94f5bf6" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="44a17ba2486f45d18833d5c14482e086" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="e8afd1e9ed224977ad02dd0335ad4dab" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="95a6115a741b4571afb1333159d4c746" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span></span></td>
								</tr>
							
								<tr>
									<td><span name="record_zhinaj:jiaofeisj" title="pos||"><span id="6b80469e04ce4db99980d27bbec71dc1" class="nlkfqirnlfjerldfgzxcyiuro">8月1</span><span id="a8329b0be94c4f96bd487130ce9e8be1" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="5de2071d48ae44c9ae01acf790812424" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="c7eb98ed138146289a400ab6f697da48" class="nlkfqirnlfjerldfgzxcyiuro">7年0</span><span id="c89d2d14fa3d4f85aa703240c84cf3dc" class="nlkfqirnlfjerldfgzxcyiuro">8月1</span><span id="028ba6ab887840508402e2987cc8aba1" class="nlkfqirnlfjerldfgzxcyiuro">8月1</span><span id="40b5f07eab504c3caa6966a7b9d43efc" class="nlkfqirnlfjerldfgzxcyiuro">8月1</span><span id="d119955e475c4de98b37e5eaccbc6e4c" class="nlkfqirnlfjerldfgzxcyiuro">2日到</span><span id="741dbfbb1d5c46b4a34086d4b65aa6b1" class="nlkfqirnlfjerldfgzxcyiuro">201</span><span id="ac4667280b9c4471a208170b0afd8b76" class="nlkfqirnlfjerldfgzxcyiuro">7年09月11日</span></span></td>
									<td><span name="record_zhinaj:shijiaojesznd" title="pos||"><span id="1dc6f21c211342b0a3f99233d7729a9a" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="5fd5bb9e4dfb480aaa5900cff2440ee8" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="2a3b1507043244a3ba509a50edfa4660" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="c5cba7e1bf914fc3b16f6671b0dbad4e" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="9fdadced6ac745ab9056aeb55c7409a6" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="75a58d90894c4a04bb4f293bfb03c310" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="ef5f7dd61c824a32b48301cfbf176271" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="bfe5412ccf574c4dab3408e6a9cbbecf" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="b2ec05c9c6094cf79f7b5acc8a68874e" class="nlkfqirnlfjerldfgzxcyiuro">5.0</span><span id="f987302b64564edb8cca3ddbe194c16c" class="nlkfqirnlfjerldfgzxcyiuro">1</span></span></td>
									<td><span name="record_zhinaj:shijiaoje" title="pos||"><span id="c389d8a8e0084ac1a1aafdc0d8a6218c" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="d6f69c3fe7654398a935a3109559b4aa" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="323f0d8ae76c429980a2b649233743ed" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="3d2db2f564e14b03ae52c970265a8b79" class="nlkfqirnlfjerldfgzxcyiuro">3</span><span id="653bea8ecb9a49179a946858201e7390" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="1ed322b208174be8a5a06b27bcd6c299" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="b89fa44a3f7643368482a422feb8ca5e" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="5c4a39cde061454db4681ab422d3fe73" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="7c990768523746cbb5e71cc509114e97" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="2c5f0aab5d4547869f15176b1660d744" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
									<td><span name="record_zhinaj:zongji" title="pos||"><span id="d476e68499834ebaae7e01236cfd41b2" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="535c74b44e514a4baf13289830f8c143" class="nlkfqirnlfjerldfgzxcyiuro">6</span><span id="704b011b36f6432e8585da786b08930a" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="377cc6d9926141e69dbe040377a6bb48" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="1e7dcf1978fb4e8d98ab06cb85ece8ab" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="6abd54c1eab14cdeacb4f2d360c9cb1a" class="nlkfqirnlfjerldfgzxcyiuro">.0</span><span id="022cf7beeee14ffabb2333a2374aaca0" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="529d5b6aec014b41b1f23c944c3ab5ca" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="6e2bff7af24b4ca998e06cb3964adc85" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="ce86edf929b94af2981db916b5a6490e" class="nlkfqirnlfjerldfgzxcyiuro">.0</span></span></td>
								</tr>
							
						</table>
					</div>
				</div>

				<div class="imfor_part1">
					<h2>
						收据发文信息
						<i class="draw_up"></i>
					</h2>
				</div>
			</div>
		</div>
	</div>

	<div class="ft"></div>

<iframe id="downFrame" frameborder="0" height="0" width="0" ></iframe>

<span style="display: none" id="65353130323462323b6d3238383f373d2923262b26712321792f7b7e282b28290c471615161712104b4c49131c194b1e05090500500154540f015c0a05055b5d246d2077267022747e7972787e7e7a2b69356b3065363065686f3f3f6c3e6d3a01024e5502015155585a5a530a0c5b5b1643144b1543454f484b484a1e4a1b4ab9b9bbafe1e0bee3e9bfb2eabfecb7b8a4f2aaa0aca7a1f5aca9aea2a8feacf9c290c49588919191cacc9d9bc89e989d83858687d5d78087dcdbd88b8b898f8df6f3f7fafde9f0a5f1f1aefff8f8fef8b1e4e6ebe4e3efb2ebefede2e5bfe9e98180d585d5d5cad7d8dedcdcd5d488d7c2c0cbc791c2c6959b9ac29f989fc89e653531353067672b3e6b683b6a3436382674762220252f76797c292c2a252c2b45121a1246401f1f044c4b491a1a181604570a560c01035608580f085a095b0722757621217c707129657d782a297d2c626630356c6562676b6a63396c383a6604500353550302050a0d465c5c0e58091542104b4d104e43404e431a4e491a4bb1e2b2e2e6b6b7e1baeabea7beb5bdbef1a5f6a7f0f3f4a6acf8abaafda5faabc5c492c291c0c09fcec89b9f80989e99d4d58bd68c828f87db8d8c8cd8df88dbf7f5a4a7a0a1fffff0f0abfbfce1acfbb4b7e7e1b6e7efe2e0bceeb9eebebcb9d4d5d3da87d2df81dd8dd9dfded9c2dfc4c59691c69495c699c8cecf9aca989d3163663634346764316f3a3f6f6e6f2320262170702370727a202b2b282c7b2819101516161511111c1c1f1b4e144c181c035656015652560c090a5d0a095f597178727625262573717e737d2d797c2c667d336a6d6162623a6b693f696a6a6b02530050065050055e59525e0a58575e16475e4741161243401c1c1f19441c4bb7b9e1bae7bcb7b1ecebbaedbebaefbaa0f2a7bfada3a1a5adaba3ffaaa9aefb94c094c79dc7979f909093cd95ccc89980d3d48a9884878ed98adcdadf85dd89f8f5f3a6f1a7f7ffa9fff9adf9fefff6e0e0b7e0b2f9e3e0bee1bcefeeb9edbcd5d5d680d184dededdda8bd3da89db8b92c290c690c6da9299c899cfc5c4c99a396031373760303e6d6c3f333f35383d237221212773263b7d7d2f2e247f7c2f4514101b104340454a4d1f4f1a184b4b5201510402050501140b0f0a0f0f0a5d75272173727175737d71727879287a2b356662626c316f6539756e696c383c3b06585a51015752555e5c535d540b5f5b43134516474512144d4a561a441f4f4eb1e5b2b3e1e6e3b3ecb8bbb3e8ecbeedf5a1a6a5f7a4aea6a0fdfbb7a8aba7fd97929290c5c797c59ccc9e9ecdcc979cd2d28b818cd481d68a808c83908b8f88a5a4a4a7a5a4f7a3f8fdf2fcaef4f7fce4e5e2e0b0ece5e3beb8eabfeef1bcbed98784d5d7d487d4d08bde88df88d7d6c595c4c3cc97c0c69ec898cb9a9bd2c86560666734373f633d6d393f3f3e6b6e72297427722377752d787c7e7f2a26331918124146144012491c1d1a1849194b09040755570052525a0b5e585f0d065e6c247a7672762570797f2e7e7479287c31336165636d6f646a6f63633e3f693b504d0605060002015f5d525f5c5d5a5e40411316421642464c1f4a4d1e1f1d1cb5b7aeb5e2b1b1e2eebbecbcbfbbb6bbf6f7f6f1f7a7aff3fcadaeabadabfffac399c18fc791c090999b9ace9999cfc984818385d5d68282dddd8b8fdadedddef7a0f6a1e8fcf1f4fff8fefbf9f4affae2e5e6b6e6b7e5efeee0e2e3efb9babbd7d9db86d0c9ded58c8bd8dad88b8c8bc1c4c69790c797c49bc8c39ac4c5cec76132313061332a66383d3a6d3b69376d742427272273222f2a7f7c7e7e257f7b174411131011400b4a191a1e4a1c1a4900080004005107075a585e0d590b070e2378737122207476647c2e7c792e787b36626566376167626c6039626b6d6c6757565555555102015f450f595f0c0c581241164717404216194f1b1e1e491c4be2b3e3b4bce4b3bebceda6b3b4b5ececa9a1a5a3f5f4f5a3aaa0adf9f8a5f8fa9395c090c0c7c490cdcd9d879e9c9a968489d7d7808487848cd8d98285de8adda1f6f1a1f2f7a0a3a9aff8f2e0abaffbe9b7e7e0e1b1e0e1e1ede9beeee5e7ea83d5d38782d283d4dcd9d9d8d9c1d98cc49790c6c793c391cf9bcecacacfc69c65363462313632646c396b33356b22392727247623762e277a7a232f2d2a2c7d14131216151117121e191a1b494f4a0309550655015304535b5c590c08050d5c79257a73727171227d7c722d2d7b2a7a7c6764666633606768686b6d68696c69050055505c0400515d0d5f0958095e5e455d461741114e41194a4a4d1e4f4a4cb5b0e0e6e6b0e4b0b9bfb2e9bbbebceea0f3bea4f0f6a4afa8aeaafda5a9a8abc2c5979ac790919ec9cbccce989a9aced587819f8586d5d38addde88d98f878ff4a4f1a5a5f0a5feabaaacfbfdacffa9b1b3e1b1f8b3e1e0e1bbe3eaecbfedbe84d584d48684d2d7de8cdd8edede8adcc29791c2c3d9cecec09ccac9cdcf9a9a346536353467643030683b3f686b3e6b7628257222253a277c282378787b262d184212171d47471e491c18481b4817160752020a050c521b5d5f0a090a0409062272712270762574702d727c2a787d7b346231356c6761367468626f6d6e3c3a54585a015451545658505a58550b560d49404016454042444b551c1f4c444f48b2b9b3e1b5e0b2b4beede8bdb4bbe8bef3a9a2a2f6a4f4a7a1aab6ababadabfc909390919dc694939b98c9cac89acfccd3d7d7d68781d5818a8a8c978f8bdcdef8f0a0f6f4f1f4a6fcaba9fef4abfcaeb5e6e0b2e2edb3e4eee0eaedf0e9ebedd38280d2d4d2d2d1ddddd8dc898fd8d792c9c496cd96c2c4cececbcdcbd19cc665306661373130343d3d3e323f386f6d7170742775202e747a7a78792b7b322b151910474244131f4d1f1d1f4a154b4e51520306500704040c5859090a09061378792472777427752b2b7e7d787f2d7f31633365306c64676a6b6b3d3e3c6a6c4c50005301520051595c595a0959590c13484b1116444f404b4f4a48491e474ab2adb1b4b6b7e0beb8bcbab3e8e8babaf5a1f3a3f1a5a6a6faa9ffa9aff8fcadc5948e94909291929dcdc9cecdcecb9bd1d4d3d2d18282858cdd8fdd8f8ddf8ea3f2a7efa2f6f2f7f0fff3ffa8fafbabe4e5e4eab5ece4e3bcbfe2efecb8efe68285d3dac8878282d08ddad28edbdcdec4c5c1c7c594c2c0ccc1c3989f9998c66135303562296761303039323b3b3e6925202675232474752b2a7f7a2b297c7b1244404012110a134a1948194949484d090104075651050e0f0b58030e0b5c06702577277274246b7d7a7b737c7f772b6769626760306f3660606a62696b6d66585556545706505f445a09095f0e5e0e15454a47454146464941434c1e494c47e3b9b2e1e7b1b7e5bfa5bcefb4efbeeaa2a8f7aaa1ada2f5a0a1a2f8f9f9a7af919492c6c19496949d9d8699ca9dc8ca80d58bd787d3d28380df82838d8ddddbf1f5a6fbfda7f4a3f9f8a8e7a8feffa9e3b7b7e5b0e2e0b1ecbbb9e9e5e9bfe984d28685dd878586dcdade8ac0dadad7c0c99195cdc2cfcfc8cdc8c2999ccd993565676666613f316b393f6f34213b6a7626227076742771292c2e7f782a272b191644111c1c1611491c1f121f4e021706080b55010255020e0e090f0b0c085d222273717d2422767b702e7c747b2a6361336b35366532366d6e393e683c3f6e0150515a51555f010a5f5f5f595b5f0c5c40404b1240144e491c194f1e494a49e6e3b6b3b4e6e5e6b1b9efe9b8b8efeea9bda2f6f5a6a4a5a1adfffef8feaaf9c5c7c0c6c090c2969f9f9a939e9998ca81d39e81d085858fdc89db8dded9888bf6f9a6faf0fcfef6aafdfafbaafdaaaee9b5e0ffe2e5b4b1eab8b8e8efe8ebeed48383d6dddd80d7d1d1898ed9db8a8dc595cac0d891c5cf9b98c2c99a9c9cca3035326761643232396f3f686a6b6a6c7677272020392f247e7b79227a292f2c41151646131647454a19124d19481b1703070b0256561a040b0b0803045f5f0b742026777726742578702c7f2829782c686461613633637b616e3c3d396f3f6805555501505d5f5051080c0f5a5a5758134347174c144212544818191f191d47b4b8e1e2e7b1b1bebab0bbe8bdbab7baa0a9a6f7a3a3f0f3fab5afffacfbfcfd92c3919194c392c5cdcb92cacece9dcdd1d2d685d082d3d4898a9683848d8ddda2f1a3f5f1a7f1f3f9affaa9aefcf8fce0b2e5b6b6e3efb3efe8eef7eae4eebe86d5d482dc84d2d4dcdadddcd48fdbdcc3c5c391c596c3c0cecd98c8d0c4cecd356234623c366034393d386a3e356a3672262727257622247e212d7a79317b2e11114715121515171c1e1e4a1f1f164c055307005053520e085a085d050f125d7578262576267624792d2f7f2a782b7765666067313662666f6e69636e3e3b73535800545d5150555e5f595d580e570c12144311464d45414f4b1c18481e4f47acb2e6b4b4e1e3e1ebb1ecbde9b9bfeaa6a9a5a3a3f6a0a6ababaffefff9fcae998dc492c29695929f919ccd9e9e9acb8388d086d08d83878edbd88d888edb8ef2a4eef5a2f3a3f4f1acfcfef9affafbb6b3b4eaede6e2e6ededeebab8ebececd8d5d2cfd3d4ded6dd8dd3dadfdbd6dcc494c5c0cccdc0c4c99fc898cec9989c3234606128673e326b3e6b6e383a39387625232b2c2c2e2e2c7c297a2c2e28281719121717091e15114f19181d1c48180109060100560f52005f5c090b0e080e2424262720766a722b787d737e2c2a786733306735616435616a6c636b386e6f02020157525d544b500b535a5c5d0c0d12424747404c47151a4e4a494a484e1de3b0b2bbb4e0e0e2a4efb8efbae8b8b6f4f4f7a6a3a1aff2a1fbffa8aafea6f9c5969596c1979ec69d85c89a9b95ca9cd3d7d386d2d38282dd8883da8e8f8b8af6a0a6f7fcfdf2f4f9ace6f3a8ffffade4b4e5e3e2e0eee3efbbe9babfeaecb9d1d4d687d7d5d2d7d98bddc788d9d68dc3c3c4cbc3c795c0cccac89d9dc49f9c37396134653431356d3d693b2039373f242674272d7172762c2d282b2a24782f44431513461c1e1f49184e1c19011a1b53095356500c02540e0b0e0f0e055c5970237170722074717c2f2e2e7a786276663262306c3162646d3f6c6f6c6f663e54075455505607045f590b0a080f0d4341121412101145454e494f42481b1c49e1b6b2bab5e4b5e2bcb0eeb9bfececbcbca8f6f5f7a0a0a4a0fafbf8aba9a6f99698c093c5959390cbcbceca9ccf9c9dd19dd0848483d3d5dcd8df8a8fdc8a87f6a7fbf2a0a3fef5fcabfcaef8aefbffe0b7fee5ececb2b1ecebbfeeede5ebebd8d38381d58783d3d8d9d88edbdfdf8c91c5c1dfc2c7c6c4c8c8c2cfcbc8caca3465303b3c3d65356c6b6b6d3a35393f2124762738707325287a2c29247f7d7c1015474242441012191c494f1a1b4c1f03025106051952540b000b0d0f0b0a5c7922762622247e74297c2f2f297c2c7d3560346a32347a6360386a3a6f69693d01535a57515403060b0c0b080a095a5a4947424a43474f5b404f4b424b444747b7b3e4b5b0e4b0e4e9e8eebebbbbefbff3a8a6a0a1a0a6a3b4adfeaffdadadab959995969791949790cbcc98ce9f9bc9d4d5d7d0858685d6dd9582da8e898c8cf0f0a4a6f7fcf2a6ffaba8fbfcfdfdfde1b2b4e4e4e4e6e0e1eef6e3e8efbfeed6d8dbd2ddd182d3de8c8c898dd88fd7c3c99697c09695c79ecb9bd798ccc69b32376460603060303c38323a6e6c3c362073247227227026282a297f307c2c2b17171a17421040154c1d4b4d4f151b17000753060450550e5a5c0b5f04110d5924712020212072772d787e792a2e2c7d68646431673764366d6d6f696f3f723a545603065101535f0d0f095f5d0b085644154043404547144e1b4c4a1d4f4953e6e4b3b3b4b5b7bfececbeeab8b4b8eef1f4a6abadf7f4a1a1fcaaabaeacfafa8c95c09697909097cd9099c89c999897d4d386d58d87868e8adcdf8ad9dbdcdef8eda7fbfcf6a0f0aefdaff3a9affaf7e7e4eae2e5ecb7b4baeeedeceeefbbead785cedad0d084df8a8fd289d5d8d8dbc9c7c4ca91c7c2c6c8c89ccccd99cac93562662f656332666d393c3e343b6b3e2472262b762d26737a2e232a78247f28161944410846121f4a1b4c1f1b484f4d560504010c0c5302080a0f0c0a0e080821247520716970217f2b7d7d7d792a7b62376660373637666a6e6b3f6a3f696e5205015055504a510b0c595b0d0f5e09121440471144151619484d1e181f4a47e6e7e1b1b5e4b4abebbfecb3b4bfe8b9f3f5a1a5a0a1a7a2a1aeaefaa9fefdabc699c4c094c394958498cc9aceccc89bd387d3d6d1818580dbdb8d8e8a8a8c89f5f9a3f2fcf6f0fefee5aeafadf5adfae3b3e7e2ecb4e2e6eceae2e8e5bce7ebd080d6d6d0d3d4818a8fc6d8dbd4d9dac4c3c4c4c49497c3ca9cccc3cdceccc86631673a343d65376a393e27383f3f3b212273767273212e2c7f23787d7e272e4414431a161213111c4a4848004b4f1f5506075655565203080d0e030f05075e25777b27757424712b7f7d292e612d7d676264626365663369386e623a6d3f39535153575c03565e095c5f0d5f5b42591512164b404545134d18434f1f4b1d46e4b3b4b4b3e4b5b7baebecbde8b5b6a3a5f4a6a7a4a2f7f3fdfbacfaa8affaaa999394909793c296cdcdce98c8cb9c9b9c8782d687808687dbdf8b8984898f87a4a0a6a5f2a3f0f4adfbfba9f4fdf6fab5fde3e4b7e0e1efb9bbe3e3b9b8eabed7d2808687d5d7d3dade8cd9de8988d9c2c0dec69597c4949acf9fcccac5c6cb3638303a363464656e686c683b6c366b2174263f76717276207f22222f7f7b2f14421a111d4610411f1e1212494f481805080202180c000e5d5b0c02595f5a0d78752777742427737e71782d7475772a3633656267793035693b636a3a693c6c5755565a00035f520e5d5b0f550e5c0941154a434d425a434d1b4d181e451c4ee6e2e3b7b0e3b4bebbefb2bfb4bcbab6a6a2f6f7f5f6a2bba1fda9aef9aaa7a798c3c3c290949396c9cf9bc8c8cecf9786d78bd28385848f94898e8b85858cdef3f3f7a0fdf1f4f2ada8fcf2adfffeace5b4b1e4e0e7efe6e1f5ededefe9efe78383d7d58184d282d9ddd3dbd8dededf969596c4c1c397c1c19bd69dce9e9a9b313937363c3667333d6f696934683a382020732a2371252e7b2e7f372d2f2a2e134013474513471e1c1d4f124e4b4f1803520b50065707010f0a5808105b0a087579267425277372797d7b7f7d752a2d31656a6064366e653b683e6c3e713b6b02070701505757555b0a5e5f0a5c565f11154343451746434b1f19421a495246e2b9b1b6e1b4b3b0b8bdb8bfbdb9efb6a0a5a1a3a4a3a7afa9f8a3afaaaca9b3c2c0969a9d92c296cc99929d989f9eca89d28084d5858587888e82df8fdcdbdbecf2f0f5f7f6ffa1fcafacfaaef9ffacb5b3eab2e4e3b0b1eaeae2ede5bcecb9d8cdd1d7d0d2d6de8ad18e88dedbda89c4c2cac691c39495c1cec3ccc99898c936642e67313134316b3e39683c683b3b2326212a2673252e2d7b2a787e797f7a4142160f464747454e104b1b4a1f481f040851000d060756085f025d040f060f7977777a687623737b7f292e7d2c2d78606530606d34626368606d3f38396967585404565149055e5c0f5e5a5c5b0a0d4940464317104f1419404e4e4f4c1c4de4b4b4b0b0b4aab1bdbebbbab5b9eabda3f0a2a7f1a7f5f6aaadf9a2aeaaafa794c393c79792c58bcecacc939f9a9ccbd6d28681808dd58281dddbdf8cd8dbdba3f9a4f4f6f5ffa4e4a8fba8f9f8abfde7e3e0eab2e1e6b6efe0eae9ece4bdbc85d184d2d1d482d7ddc58bd98e88d889c2c69497c2cdc296c89ac399999998c963603a3230666333693f266a393f68392377262326242e232a782b222f257a7d1343121046401716111d12071f194c4952070603050007070c0a095d040f5c0c78712077272425722b2d297a6029287a62353361636d64666d6d63626e65663955075551555100025d595c080f410f5c13434641431345161d184e4849454719e2e0e6b6b3b3b1e3b1bdecb8eeb8a2b7a8f2a2a7a7aca6f4a8faaaaff9acf8fd929592c196c192c2cecc9acd9894998386d08381828c86808a89d8d888de8bd9f9a5f6a2f4f7fef1fdfdfbf9fdf5fff8fce7e1e2e6ecb3efe0bbe9e2e9e9babed483d687d0dcd181deda8f8a8edcd68993ddc4c19291c391c99dcecacecaca9b39336330616665323b6f3f6e6d3e6d6d22773e242c2174737d282878242b2c2b1240111b42104215111b184d1b154a4a0352501f56045506080a020258040c0f74782777267270237f792f2a7c79797d35616264786d6765396f6f69396f6e6a0255015554070206580b095a0a0e0d5a4115451245594f161d1a1b4f1e494c1cb7b0b6e2b5e3e7e6bdbeb3b8bfb4b8b9f2f0a4a6a4adbaf6a8faf9aca9affaa69891c797959d9e9ec9cd9a9dc89a9fcad681d7d0d080809bdc818b8edf8e8688a6a3f7a5f0f0f6f5f1fbacaea9affcf6b3b3ebb2ecb4efe7f4ebeeb8b8ece9be8487d286d1d1d2d2ded0898d8adc8fddc3939692cd93c0c2ced59e9acece9bcb333030373761323638686b3d39696a6a74722522272572242e7b362f782b2f291643401b461c1213184c13494a1d1a1d5500065201045707595d5c170e5f085e75777120227624257c7f732a757c7f2c63306467626630666e69636c706c663a0352560757540202585d585959540a5c44154146171712434f1848484b511c4ee6b5bae1b5b4e3b4bbbfbeb2b5bdefbcf1f5f6aaf6f0f3f4fdf8acf9a4fcb2a7c296c691949194c3ceccc99f95cb9ace8385d785d683d4818d8fdb838fdc8993f1a3faf1a5f6ffa1aafeabfff8fef8adb2e7b4e1b1e7b7e5eee0bce3e4ebbceacc8384dbd6d7d083d88bdad9ddd9dbdac993c796c79497c1cacaca989d9bcbc8372d3433366730373e6d6c3a68353a3e2772702a242025737d7d7c79292d2b2a46100e1511431e41181a121349154b1b06030b52025102025e00085d595f060f2276746f732727217c7c7e7a742b7d296461316a36606466616c636a3f683b6805030602485453510d08525a580f5a0e40454b434c4d14154e184d191f1b4649e6b4babae0a9e7b6ede8bbe8eaefeab6f1a0a6f7a4a2f4a5abaca8a2f9a4fffbc297c0c5919c8a96909bcecf9ccb9e9e8188d787828d878ededa89df85898c86f8a2a7a5f1a0a7ebfdfaaffca9fcfaffe1b5e4b2e0ede2e2bab8e2e3b8edb8be81d186d780d280d4c488dc8dde8fd689c2c9c39096c1c7c4cec19998c8cdcd9961353a62373134373e2569686a353f362273212721732225797a7828782c7f2a13441442461042164e10064e151b191752010a0055050203000e0302595e0b597274207b7d77207e2a7e29677b75772934306732676165366c6c3f6a643c6c690251555307045e515c5b0c5b40090a0c4814424117131545484d1e1e44441d47b2b3b6b0bdbdbfb1eebdbdeaeda1eabdf4a5f4f6a3aca2f2f9ffaeabfefefffbc3929296c1c7c7939a9c9a9d9d958299d3828580868d828edb89db8f888d8886f1f5f2a6a6f1f2ffabfcacaefafdafe3b1e0eae3e1e3b2b4e0e1eaece8bbe6bed9d2d48681d7d5d38b8adfdbd4d88a8edc9297929697c0919ecace99c9c9c89a62633436316730663c3e3a6f3d6f3a6d713d2620702c72232b7c297e79282a7a1647404616401f121d4d1819181a1a1709091e045556040f080f0a0b5e0c5d0b2272707b747d2026797e787a782c7c783663377f3260326e6c6b6b6a6b3f3d3c545457055d5403060c5b5c5a0a5c0f0e42121040584d10161b4a42194f1e1819e1b5b5e5e1bcb7b0bcbbebbfbfbabdbaf5a9a5a3a0b9f2a6afaca2a2aeaea7fc94c096969dc6c4c39d9f98cdc8c89696d5898486d7d69ad48fdcd9d98e8add8bf7a2a6f7a1a3f1a6fdfeaea8a8feafaee8e3ebebecb0e1fbe8ebe3e2baebbfefd7d58485d08483d3d08cdddedd89d8dd9193919796c7c1c2d4ce9ccbcac9cec7373333663d3136633c6b333c3b3d3f6d26202a2025262027213523282d247c2f1943411b154712174a4a481e1f1a1818010351050600010e0909160858040e0e2677217a727520732d2f78292d7d767c363267626c376e613a686b776b683d3903045752020454055c510f0e545b5757474216174d4743434d481e4c5018184fb9e0b2e0e0bcb5b7bebdeceeeeefb6bda5a0a0f2a1a3f0a4a1aca9fea8b1faafc5909b95929391c4ce9c9ec8c998979d8586d08084d4d5d58b8e88898bde928ff0a7f4a0a2f1f5a6aefeaeffadfbf9f6b6e6b3e1ece6e6e1b9efe9e3efb9b8f385d2d2dad584ded38cdedc8ad8d58b8ac8c296c4c1c0c294cf9d9fc39ac8c89d2c6466663033603f306f333b6f39373d7628212277257321282923282e7f2f2f190d15421c1115161c4d1d481b4b1a18090953025253050e0e0109030b0e5b0f70276e267d217172782a7d7f7d792f7b6668316b356235363a68383e3f6d6d6c5505574f52505f575f5a095b0f0e5d09441411414c464e441b4b4e1f4949481ae2e0b0b5a8e1b2b3bebabdb9babebceba2a5f1f6f5adf3affca8f8f9ffa9fffa929695909289c593cb91989ecece98cad2d3868380d7d483dedd8d8bd9848d88f8f1a3a5f2f1eaa4a9faf2afa8fcaaade0e6eae7e5b3efeee9e0e3b9efeabbebd680d6d0d3d7dfcbda888fdfdada888ec49296c3c09397cfc1cfcacbcbc5cc9e323464613d34376624306f6f693e3f3f27297675202123212c202d2c24792a7e43171216401213421a05121b141e1c4c53025105520702545900585a5f0e0b0c74247470737475227e7c662e7a2b2b7733326063306c63636b6f6339643e6c3b04530454025504045c590e4758540c0d41144b414d40454e4c1c48191e454646e4b4b1e0bce3b6b4b8bcb2bba0beeabcf5a7f7a7a0f0a2f5a9adf9ffaca4ffadc3959797c696c392cc9e9ecec9819ccad2d3d180878085838e818e8dde84dc8bf1f6a4a7f0f3f3f3f1fdacfcfbace2fde6e5b6e2b5b0e6e4bcececefefb8bdbd82d980d3d6d7d3d68edad9d889de8bc3c7c3cac7c39095919e9acbcec8cbc99a62676765663065623b6d696f3c3c686d3c2970222c227727217a7e7e2429272842184447124640164b101a134a1b1b1d061d065255000f555e5f0f580d0d0a5e70747b73732673267f797e73747d2c2b36357e6b676165353e3f3b6d3f65676b53555302000005015b580e0a5a5b5e5b4945135f424314141d484e434c454f4fb4b3e1e5e6e1b3b7eebfe9b2eeb5bbeef6f3f1f5b8a2a5f5aba8a3a2a5fffca890959393c7c797c1ce9cc99ecd9e9996848086d08499d784db88d9dadade86daa4a3f6f2f3f7fea6f1aafafbf5fdfef7b1b7b0b5e0e3faeee9eaedbfe9efeaedd882d1d787dcd3de8cd0d88dded48cd9c2c291cbcd90c7dbc99c999fcfc89a993237376730643066303d696d3c3e393a7372232372247473342e222f2d797d7d44441516451113134c481a191b4b161f08035655070c06550a155f58040d0906257827747c747223707c282f7f7a7f7d69683737366063636861766c3e6e696d565707555d5d07535a5b08535e5c575915464a42171744411b4f1f571e1e1a1de4e5e0b7b1e3b7bebcbdbfefeeecefeea0a4f6a0a4a0f0a7aaffabaeb0afabf997c391c2c2c6c496cb9d9c9dcf949e9d8583d086d5d3858fd9d8dcda8f91df8df6f0f3f7f2f7f1f0f1fbfef3fdf5f7f9e7e5e4b7e1e0eeb2bde8bbe8efbbf2bcd5d5818782d38083d18f8fdf888fd88dc8c397c6cdcdc191cbcfcac8c49f9dd332673231373462346e683e3938396c3929737071722d722f7d2b2c28282f26260c45141340411e164b4c1b1a1e19191e04530a0a51530e025e5f0d58050d5c07216d2670727570747b2d7b29787b7a2c61686b64653062353e686f3e3f6c6e6658574e54555d5e50500a5d0e545f5f5b15404b4a4c174e401d4b4a4f1a4d1b49e2e0b3afe0e4e2e4e9efb9eabdb5ecbba4f0f3a7acf0afaff9ffa9a8abada9af99969b9788c1c3959a9f9acd9e9aca9bd4858a81878dd7d1dc8b8b8a848d8cdba6f2f2a0f2e9f5a2fbfeabfff8fcffade2e5e6e5e0ecb7e2beeabeeabebfbfe683d3d181d5dccad0dcdfd38eddd48ddb9392c0c7c6c29596cececdcacf9cc79b643730663337632b3b3a6f69696f373722222175207327237a2a2e2c7a287d281145121040151f11044f124819491c1909070052500102550d58590b5f5b5d0f2524267a707176707a6529727a782d7b636931616c31626f6c3b3b6e693f3d6d04045100025400055850460f090e5b5e114014454c4d4343404943194c454619b2b3b6b2bce6b3b3bbbebda7bfbcbcbfa5a7a6a5a6a4f7a3acfdfcfdfdadabaf99909a90c7c795959191929380cc9d99d3d4d1d78585d4858a8ddedadadcddd9f7f0a4f4a1fdffa2ffada9fef4e1acfdb3e9e4e5e3e7e2b2ebe8eebfe5efe6eed2d6d6d3d0dcd5d48edadadd8adcc28dc7c9c5c597cccec29a9cc2cfca98cc9e366361376530306639303b323435362320247420772370747b787e2328792a7a1844171b10111616194f4f491a194a491c045057560005025e0d08030f090e0e72797270227224777a707e2f78297e7c607d3462316d6762683a6d6c646b6a3d52575a5655035f530e0809590d54595713445e4b434217444c4b1f181f451a4be4b1e4bab2e1e2b7edb9babceaeeedeaa3f7a3bfacf3a2f1adfdada8a9aca8fb94999293c597c4919b9a99c99ccfcb9a82d3d3d798828283ded889838c8bdfdda6f5f6faa7fdf3a5f1afafafadffabffb1e0e7b7b5f9e0b1e1eeefeeb8efe6bbd4d3d685d181de82d0d08fddd5da8cddc3c9c2c7c7c5dac1cccb9ccd99c8cfcd30313a373d3265653b3f3e6a346f3b6a25272b23212d2e3b7e2b79222b2a287910124110104445434a4a1d1a141c194d5654005203075255140b0f0e5d585d5973247074747173257b7078297f7d7e7c31646230643333663c756d3d3a6f686859595207070752515d5e0b5f58595b594512401141464f4f4c40564a1f4d1d1de1b1b6e7b1e6e4b3bfecbbb2eebbbcbfa6a2a2a7f7a7a3a5faaafeb7a5a4abfbc2c79ac1949196939c9dcf9a959ccacad2848bd6d187808e818bd8d990d88e8cf7a4a4a6fcf5a5a1abfdf8f8feafacffe3e5b4b2b2ecb3b6baebe3b8e4f1ebecd4d583dad7dc85d48addde8888dad78c9597cac29190c1c7ccc0cc98c9ccd29935353a313461376631316b3f6a3a6c3627247676232374242d7b2d232424263319144140151315464e4a1b1b184f1c1f08570b5004540e05010058090d085b5e6c70277376757024792d7c7c7979287967306a3532646f326f6c38626539663b034d5a560000525151510e5d0e5f5a5e441010174d4d1343484e1e1f4a494b1cb1b6aee5e6b7e7beecece8bdbdbfebbba8f3f1f1f0ada2aefbada9fefaa5aca898c2978fc2c3929e9d9999989e9d97cc84d08483d5868f8f80dbdfdd88d8d88cf8f8a7f5e8f5f5f0aafbfbfaf8faaffbe5e5e5e2e5edb0b1e0bdbeb9efbcedbd8182d3d480c9dfd18add8cdb88d98bdd9190c6c5c49794c6999f9fc89dc99bc66463603134332a313e6f3a3e346c3a3d25222a272c77772f7a2078232c2d2e2a114546111116130b1a1b1b484e4e1e4d040503040001010401585f5a0a0f585a7423217520737172647f7b7a2d7a2c78636233376d6137633a386c3f39396f6952505405060653560a455f5f580c085e444217424d4042401c4c18434a4e4e48e4e3e0b0b5e1e7e4b1bfa6eebab8b7b8f4f4f7a7acaca1a3afa8aba3fefbf8ab97c2c492c0c6c7979d9cc8879dcf96cdd283d68a85d78e828cda898a84df8e86f1a2a7a7f5f0a2f1fbadfcf8e0fdfbf6b2e6eaeab5e3eeb5b9edefebbfbceaecd4d3d6dbd3d1d3ded8d88f8d89c18ddbc59793c7c691c691cfc1ce9ac5999fca3535606236323663396832686e3f226e23772b20752774767a21292f7f792a2712121a10471410464d1c484e4a1e4c0352005605500d5007095f0e03080d070f78727a27257c72762e7c7b7e287f2c7d7c303367306d32656b3f3b3f6e696e3a5200005757545e5550590f5f5d595e58125d4517161145164f4f1e1e451c4a4fb6b9bab3b7b1b0e1b8bcbcbde8babeeba4a7bef5a7a4a7f5afa9aca8aafcfbabc391c69b91c0c0c191cace9e9ace96cd8382d09f828281d4dd8e8eda89de8fdcf4a3f0a7fca0a5f7acabf8f8aafafdaae1e0eaebf8b0b2b1babdbbe2e9ebbfb9d3d5d681d1dcd7d2d08d8ed98889dade9697c1c395d9c7cf99c9cecb9e989a9a39303633326167623a6c3c6e6d6b3b3e2626222176743a232f202e22252f2629161943171411141f1e1f1a1b4f1f4a1b060107560350051b0e0f0a5f5958580f7773777470277e772a7f73737c742f2b3568346436606f64743d683a3d3c3c6855535a57575156505b5059095a5f595848101140431110414855194b451c1c4be5b7b1bab1b7b2e3edb9b2b9e8e9eabda7a3abf0f2a7a3a0fda1b6fea5afafaf999792949190969390cacecacd9dcb9a80d286d187d3d08189dade978bdedc8df5f6a1faf1f6a2f6fcacaef9aef5ffa9b5b0b4b5e2e2b3e1eaecebeff0beebe6d18487db8087d0d0d0dd8988dfd5d88e93c0cac69097c2c1cfcdc89e9fd1cc9b39653536373233616c383e3d69686c3f7520212271262e2e292f2b7e7d2f322844191b1510411546181e4c1f1819171702040552060555520a5b5c0d095f081326782471752475217d7c2878782f77793235606232626465606a6d6968386f664c55520655015e565a5c5a595b590d5e49484a47101044461b411f4a491b4f4bb6adb6b6b1b1bfb1ede8bdefb8bcbaeef3a3f0f7f2a2aef1abaca8f9afafaeac95c48e9693c19ec49fcace9f9a959a9b8986d0d2d6d18e83da81db8d8c8ddcdaf8f9a1efa1f1f0fefdfcfaf8f5f9faf9e4e8e3e3ecb7b0e7bdbfe2b9ece9e8edd7d6d382c8d5d282dcd0dbda8fd88cde96c597c7c6cd97c4cb9cce999f9fcbc93634346132293234313d323a693d3a3e22202624712474242d2c7f79297c7a2e4618471215120a454d181f1b4a494a1e0752540750560e0f0b5d095a580c5d5e7976277622247f6b7b2d78297c7c2c7e62306a6260303562606c63396b6f6b6851555406535355554408090e59580d09144011471141451448184b481e1c1a4db1b4b4e7bde3b0b7bca5b8eee9efbeeda6f4f3a0acf4a2aefdafa3a9a4aeffa799c59390959094c198998693cecb969ad184838685d18383dc8d89da8adbdbdea4a3f1f3f6a3f7f3adacf9e7affdfcade8e9e3e6b2b7b4e1ecbabfedbdbbe6be8582d1dad6d582d6dfdadbdec0d58a89c2c493c797c7c791cfcd99cbce9ccfcc656436603c663432303f6c3b3d213f6972252a77702323272f2a2e28792b272e431213111c16401110111e1b1b1d024c03500b07040750515e0d0b0f0f040e5e75202470277272717b2c727a2d2b7a6333636a316762326e6e3c6b38686e6e39015007010151525f5a08520d5d0f5a585c444b10414046444918434319494e1eb8e3b1b6b4e7e3e3ecece8bfbbbabeb8a6bda7a2f0a6f7f2fbf8f9ffa5fcaaa8c5c3c09bc29196909e90cc989d94cdce85d49ed2d58080d18bdbd98cddd98c8bf9f1a3fbf4a1f1f4fbf8fef8a9aca8abe5e5e3ffe2edefe1eee8efbaece4eeead484d2d586d1d6d4dd8cd9d9d4d8dad9c597c596d897cf93ccc9cac99ac49cc932353b336767316638383d6f353c366a24747620773970222a2d2c2c247b7c29151016111d111e1218191c1e48481c1a050354070c501a0301585b030b585d0d742070772577717f2b7d7b7c7c742d7c6369676265376f7b6e6a6869696b386c01070607505d0405090a5f5d0d0c5d57441441424344474554484d4a1f4a471cb4b9bbe6b2b1e2e4edb1bde9e9b4ece9a4a9a5a1f1a6aff1fab5aaa3a8a5affec4c29694c190929ecaccc89fca9ecacc86d58ad78581d28fdcdd968a8a8c8c8af1a7a7f5a7f2fff3abfaf3a9fbf8f7f6e9e4e7b1b7b7b5e7e1eabbf7bdebefe8d18481db87dc80d3dcdd8fd3d4dd8ddfc490cbc2c5909396cf9d989ad0cbcf9b61603b3034343e343c3d686a346c366d27202b7677702722297c78297931787b414310111513151e1a191e1a4e1e4c1808545150035107000d0103595f04120672747a77742022757a707e7f2a2b2c7633323030316d6661616e393868686c735007505b0754545e09585a5c585c080b49464b4a101014464c48424c1a194f4aace7b4e6bde0b0b5ecbeb2b9efb9b6eda6f0a1aba0a2f4f5acffa9a8f9fbadfec18d9b959dc09e949dcdc8ce95cc9a968185d0d18d8684d18cdd8f8c8e8fdf8bf5f6eea7f4f0f5f2f1ffaefbf4f4abfbb6e0e1ebe5e6e4b2baeabeb8eaede7ee8282d7cfd3d4d184d0d9d2d2dfd98ad6c4c596c096c693919ec0cf9f9e9f9c9d6134343128333f3e6c6b333f6a6b376d21252720702d74717e7b7c79797f292e1947434747091143491c4f1d4f1d181b0507065005530f02595d5b080a5e0b0d7573212775266a712a2c292f7a79772a343267673231663569606a3a696d3f6c05025b525c50504b5f0f0f53550e5e5a414014164040461440491c19454b4f48b1e2e6bbb3bdb6b6a4bfbcbfbaefefbcf5a4aba1f6a1f3f5f9f8feffa8f9aefc93c29bc7c0c1c3939d859cca9495cc9b86d28bd584d682d58cdb82df8d8edb8af2f9f4a6f1fca7a4fdffe6adafaef6a9b6e9e7b7e7b4e1e3babdeebae5b9bae8d8d2d3dadcdc82d2d88a8fc789ded7dbc9c9c1919190cfc6ccc19ccc9d9b9a9c6334303b34633e333d3c6e3f20343c6d2829232026212e2e292d7c2225247d2f191517161d434246111e4c4d1e014d1b05550657570200070c0f0e0e0c0c5c0a7575732727737372707c7c2f2a75627a34303432656730356f6a686f6a656f3e50030452555700055e08520d5f5a5d4346151741414545114f494e1a48454a1ae1e0e7b3e6b2b5bee9ecbfb2b9e8bfe9bcf5a3abf7f4f3f4aefbacfafda9ffad979897939696c291ceca9e93c9999e98809d8bd68585d580db8f8b88de8f8adaf6f9a3f2f0a6a3f5f1f8a8fefbfdfbf9e8e2feb7ecb0b7efb9eabebabeece7ebd0d8d2dad1848386d0ddde8dd4d5d9dcc395c7df95909396cf9f98cd9eca9acc343263613c313362383d6c3b6f6b396d20702421387427202d292b792e292a2c171512471547134518101c48484c484e5501570404195055595f5f5f580d0d092525767b747624227d7b792e7e2b2c7d6032316a60637a676b616c396a6d6f3e03595557565c02065e5d525e0e0e590b164840154443125b1e1a1c49491b4c4be3e3b5e7b0b6b0bfeabfbdb9bfefeaeaf3a0f3f0a2a2f2a4b4afadadf8aafaa7969995c69291c4c49dcb9399cfc89f9dd5d2d680828086d28895df8ddfdc8f8df0f3f5f4a7f2f2f4fbfbf2ffafa9fbffe6e2e4e2e7b1b2e4e9edf6eabaefede6d5d88381d281d6d3dad8de89dc8cd68ec49397c1c391c2ce9dc9c3d7c5c8cc9d396066626166643e3c316e3b353c373a22747177752d7225797d237d302d7f281715141316461f12491d121b1f154f1b07535401520605550c0d0c030b115b5b247577737d767e232b717e7b2a2e2c7c686160356337606e39616f38643c726e05550453505105535b0b595f5b095f0e40441447154113451e414a1f1d1b4e53b9b6bab3e2bdbeb5eab8e9e8b8eeb9eda8a0f1f0a5a4a3a0fafaa3feadfcfafa8cc2c49392c6c2939dcd93c9cf999a9c8688d6848284d0838edc838fda88dcd9f6eda7fba5a3a2f6adf0afaffefffaf6e7e6b3b7e4e7b2b3e8eae9eebdb9eabb8183ced68080d4d7dfd88edfd48c8bdbc492cb9291c5c7969b9fcdc2ccc5cfcd3433362f6732636531316f6f3d3e363e2427202b2d7422272878782d7a2b27284440161b08461e1e4c1b4e1a184b4f1c5405540b01545700080a080f0c5e060b23277127276922767970737e79287a7865326637316c6e356b6e3f6e393c3d3c0202540650064a505c580e090a0f0c5e14441147421742164b4d4a434a194a1db6b4e3e2b2e7b7abe9eabebdbababcb7a0f3abf0a0a1a1a6f9abaaa3adaaaefd90c0c4c79cc79191849ccccf99cfcc96d585d6d5d6818e87d9d8db8e858d8edca6a7f0f7f0f5a3a2f0e5afadf9abf9abb4e7e3b0ece7e2b6ebebb8efe4eeeeee83878085d5d2d0d5dfd8c6898a88dbdbc1c3919092c0c1c39bcd9e9a9ececacf386434623d6664656d6a6c273f693c6b72237426222173262c7b2a287d782b2d4318151316131346104b1d12001b1b1c5254530b5156540e590d030a0b045f0674777a767c7776762d7e79727c612c7769373367603465316f6f6e686f6b666b58530357565700020a51090a5908425d13441443151414421c4d4f4f4b454846e6b0b7b2b3b3e4b6bebfbaefbbb9baa3f4a5a5a5f1a3aea3a1a0a2a8a8f8fcfec1c495c6949494949ecacccf989ccc9d9c868287d6858786da8a8cdd8a898d8da5f9f7fbf1a1a7f0f0ffa8fbf4f4fdffb1fde1e4e3b6b5e1bce0e3e9eaeceaee85d7db878680d6d3d8dadddc8ddb8c8dc4c9dec5959792c2cc9acb9e9d9fcfcb63656762676732613a6d393d3c6e376c7220733f">版权信息：国家知识产权局所有</span></body>

</html>

HTML;
    public $publication_info_html = <<<HTML
<html>
<head>
<title>中国及多国专利审查信息查询</title>
</head>
<body>
<!--导航-->
<input id="usertype" type="hidden"
			value="1">
<div class="hd">
	<div class="head" id="header1">
		<div class="logo_box">
			
		</div>
		<div class="nav_box">
			<ul class="header_menu">
				<li id="header_query" 
					class="_over" ><div
						 class="nav_over" >
						中国专利审查信息查询
					</div></li>
				<li id="header-family" ><div
						>
						多国发明专利审查信息查询
					</div></li>
			</ul>

		</div>
		<div class="hr">
			<ul>
				<!-- 公众用户 -->
				
					<li id="regpublic"><a href="javascript:;">注册</a></li>
					<li id="loginpublic"><a href="javascript:;">登录</a></li>
				
				<!-- 公众注册用户 -->
				
				<!-- 电子申请注册用户 -->
				
				<!-- 公用部分  -->
				
				<li title="选择语言"> 
					<div class="selectlang">
						<a href="javascript:;"> <i class="lang"></i>
						</a>
						<div class="topmenulist hidden">
							<ul>
								<li id="zh"><span  title="中文">中文</span></li>
								<li id="en"><span  title="English">English</span></li>
								<li id="de"><span  title="Deutsch">Deutsch</span></li>
								<li id="es"><span  title="Espa&ntilde;ol">Espa&ntilde;ol</span></li>
								<li id="fr"><span  title="Fran&ccedil;ais">Fran&ccedil;ais</span></li>
								<li id="ja"><span  title="&#26085;&#26412;&#35486;">&#26085;&#26412;&#35486;</span></li>
								<li id="ko"><span  title="&#54620;&#44397;&#50612;">&#54620;&#44397;&#50612;</span></li>
								<li id="ru"><span  title="&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;">&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;</span></li>
							</ul>
						</div>
					</div>
				</li>
				<li id="navLogoutBtn" class="mouse_cross" title="退出">
					<a href="javascript:;"><i class="out"></i></a>
			 	</li>
			</ul>
		</div>

		<ul class="float_botton">
		<li id="backToTopBtn" title="返回顶部" style="display: none;"><i
				class="top"></i></li>
			<li id="backToPage" class="hidden"><a href="javascript:;"><i
					class="back" title="返回"></i></a></li>
			<li id="faqBtn" ><a href="javascript:;"><i
					class="faq_icon" title="FAQ"></i></a></li>
		</ul>
	</div>
</div>
<script src='http://cpquery.sipo.gov.cn:80/appjs/header.js'></script>
<!-- header.jsp对应js -->

	<input type='hidden' name='select-key:shenqingh' id='select-key:shenqingh' value="2013101770396">
	<input type='hidden' name='select-key:backPage' id='select-key:backPage' value="">
	<input type='hidden' name='show:isswggshow' id='show:isswggshow' value="yes">
	<input type='hidden' name='show:isgkggshow' id='show:isgkggshow' value="yes">
	<input type='hidden' name='select-key:zhuanlilx' id='select-key:zhuanlilx' value="1">
	<div class="bd">
		<div class="tab_body">
			<div class="tab_list">
				<ul>
					<li id="jbxx" class="tab_first"><div class="tab_top"></div>
						<p>
							申请信息
						</p></li>
					<li id='wjxx'><div class="tab_top"></div>
						<p>
							审查信息
						</p></li>
					<li id='fyxx'><div class="tab_top"></div>
						<p>
							费用信息
						</p></li>
					<li id='fwxx'><div class="tab_top"></div>
						<p>
							发文信息
						</p></li>
					<li id='gbgg' class="on"><div class="tab_top_on"></div>
						<p>
							公布公告
						</p></li>
						
					<li id='djbxx'><div class="tab_top"></div>
						<p>专利登记簿</p></li>
					
					<li id='tzzlxx' class="tab_last"><div class="tab_top"></div>
						<p>
							同族案件信息
						</p></li>
					
				</ul>
			</div>
			<div class="tab_box">
				<div class="imfor_part1">
					<h2>
						发明公布/授权公告
						<i id="gkggtitle" class="draw_up"></i>
					</h2>
					<div id="gkggid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="5%"></th>
								<th width="20%">公告（公布）号</th>
								<th width="25%">公告类型</th>
								<th width="20%">卷期号</th>
								<th width="20%">公告（公布）日</th>
								<th width="10%">操作</th>
							</tr>
							
								<tr>
									<td><input type="checkbox" name="checkbox"
										value="checkbox"></td>
									<td><span name="record_gkgg:gonggaoh" title="pos||"><span id="22c774aabc474b20ab2ba6806054a3a2" class="nlkfqirnlfjerldfgzxcyiuro">149 A</span><span id="6d06f1490a5748939a567822f7ad0c45" class="nlkfqirnlfjerldfgzxcyiuro">CN </span><span id="c6c7cf49bad04aa698084ad62834d32e" class="nlkfqirnlfjerldfgzxcyiuro">CN </span><span id="50bacd7864f64f7c9ff2e8bd520a8912" class="nlkfqirnlfjerldfgzxcyiuro">CN </span><span id="95e8971c856c4c389c601f66f1caaf56" class="nlkfqirnlfjerldfgzxcyiuro">103</span><span id="496c293a4c6243b1af709787bb87633c" class="nlkfqirnlfjerldfgzxcyiuro">273</span><span id="673fdce7a69947fb9ff82e1c95decff3" class="nlkfqirnlfjerldfgzxcyiuro">149 A</span><span id="3c24385703f849d681318a8d862e2b07" class="nlkfqirnlfjerldfgzxcyiuro">273</span><span id="e90dbd99d1434a3aba3e81b566b4590c" class="nlkfqirnlfjerldfgzxcyiuro">273</span><span id="22bde4931b174e2aa8ee6bfbd86a020e" class="nlkfqirnlfjerldfgzxcyiuro">149 A</span></span></td>
									<td><span name="record_gkgg:gongkaigglx" title="pos||"><span id="eaedec69fa2246a4a370aa40c93a6eb5" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="26bb2533615f4c5789f131b27746437a" class="nlkfqirnlfjerldfgzxcyiuro">公布</span><span id="3ac13c174841401989a26419797532b9" class="nlkfqirnlfjerldfgzxcyiuro">公布</span><span id="d7d3f4fc625a4bc09196037c093617c2" class="nlkfqirnlfjerldfgzxcyiuro">公布</span><span id="248ad82651644b6bb1e77b61fe93077e" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="b6396e6f53764395be9bfe44588f5c88" class="nlkfqirnlfjerldfgzxcyiuro">公布</span><span id="737ab3e1735d492986ecb0ab74d18452" class="nlkfqirnlfjerldfgzxcyiuro">公布</span><span id="a8277421928349d89d94e2fee174f772" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="d82f4af9fdea4ea683a455552973b527" class="nlkfqirnlfjerldfgzxcyiuro">发明</span><span id="baf5c7e324034450a9ab676e6700aad0" class="nlkfqirnlfjerldfgzxcyiuro">发明</span></span></td>
									<td><span name="record_gkgg:juanqih" title="pos||"><span id="7c9d51f7a2db4e7f94e74f7f8905cdef" class="nlkfqirnlfjerldfgzxcyiuro">-36</span><span id="3f0f0a11bfb24d6cbeb4aba0cc25aabe" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="557d9938ea744721a053ca95c816e33f" class="nlkfqirnlfjerldfgzxcyiuro">-36</span><span id="f9bfca42cdb54da38840a15e4c27bdce" class="nlkfqirnlfjerldfgzxcyiuro">-36</span><span id="90481aff00d14f5094c551c4841992db" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="da65770e4b2c4b2aa266f633a033c0f8" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="68be305679164495880413a8b5e9610e" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="840b264d5a534953b0265dda67ba6387" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="892069bc8a734d99bdf74ceae5f61007" class="nlkfqirnlfjerldfgzxcyiuro">29</span><span id="9ce4ac508fe04bc2b9b6f54544c10db7" class="nlkfqirnlfjerldfgzxcyiuro">29</span></span></td>
									<td><span name="record_gkgg:gonggaor" title="pos||"><span id="734b3ed5bb1d44ffa37997a835cec575" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="a880760629184ee1a522544f2179a014" class="nlkfqirnlfjerldfgzxcyiuro">13</span><span id="6f9ed3ab30f94f98adfed9732c74a4e4" class="nlkfqirnlfjerldfgzxcyiuro">9-04</span><span id="31bf1ff00458426ba41f0b8976079873" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="6dfb0feebe854ac0bd8ed0e21a49494f" class="nlkfqirnlfjerldfgzxcyiuro">-0</span><span id="e84bee5a58304f46938e4eea26d170c9" class="nlkfqirnlfjerldfgzxcyiuro">9-04</span><span id="44d5f01125c5478a93a3f49e0148e93e" class="nlkfqirnlfjerldfgzxcyiuro">13</span><span id="22ac19c4e10b49e9b4a35e62a40ee93f" class="nlkfqirnlfjerldfgzxcyiuro">13</span><span id="4d7a424d1838452c9569f22f11ff16f1" class="nlkfqirnlfjerldfgzxcyiuro">20</span><span id="abab8ffeeafb427c9d17e2e88525f9c0" class="nlkfqirnlfjerldfgzxcyiuro">9-04</span></span></td>
									<td><a href="#"  onclick="downloadPage('/EES20/GKGG/2936/FMGB2936/FMGB_DXB/2013101770396','2013101770396')"><i class="download"
											title="下载公告"></i>
									</a></td>
								</tr>
							
								<tr>
									<td><input type="checkbox" name="checkbox"
										value="checkbox"></td>
									<td><span name="record_gkgg:gonggaoh" title="pos||"><span id="f257a2cf913747bc878c1b675062817b" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="fd7e49a5236648ba9ac9fdb8a5101b9b" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="3ad2d64fa69d407d9881b1f1b413355b" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="954d6e0b385341b0871c096b9153e83d" class="nlkfqirnlfjerldfgzxcyiuro">03</span><span id="5b8a74910f7f4d399abc6d93295d45ec" class="nlkfqirnlfjerldfgzxcyiuro"> 1</span><span id="685417b775a24956894bb5c0542008fe" class="nlkfqirnlfjerldfgzxcyiuro">03</span><span id="de490b567f974951a236e3709984d81a" class="nlkfqirnlfjerldfgzxcyiuro">27</span><span id="a3f33e0be11e44a38a7f168277d6cd27" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="cc1edfb899be45d9894363c93c7d812a" class="nlkfqirnlfjerldfgzxcyiuro">49</span><span id="e11606a3e6094f0897beda16e882dbcc" class="nlkfqirnlfjerldfgzxcyiuro"> B</span></span></td>
									<td><span name="record_gkgg:gongkaigglx" title="pos||"><span id="4f8808498148422780d85cbc5f8f897f" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="3d0f141786b9466190d6ac0817fb45d3" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="6ea2d6397bf44f1089e59c0e293371b4" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="6e0362487e244826985b3b88f7b94904" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="5dbf714c926240a49435dddc2cc064b8" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="98606cbb75ad4a0fae378435f51364c7" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="262bbcb69d97482f89638560925544f6" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="1894094cee9e4bcb91b761b54f4b9e16" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="59c3d67777b6409e9ca2589f6484207c" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span><span id="d35d0f77cefd4d1d9232812b015f816a" class="nlkfqirnlfjerldfgzxcyiuro">发明授权公告</span></span></td>
									<td><span name="record_gkgg:juanqih" title="pos||"><span id="9c65c3813bc74b9ba0c8f9ef2be02fef" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="dfd1c16ff42e4c578b5f405bd743654c" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="e2c1ac3c58494ac38ca233bf4284f271" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="b465b2a46aeb4990a0295c937c6f8bc7" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="982d730c2333411b8fa6119501fba85e" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="d7f199f93a4e431990c86724b88fd324" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="7ddcc64179384afc8ea05b34fc7e27c6" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="4399fa8ee32443309eb89d36fb94af5e" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="3d9bd13e9dd94736aeeaeaee882ffc0f" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span><span id="242b3ccc0baa479fa9b2a532b3b0724d" class="nlkfqirnlfjerldfgzxcyiuro">31-24</span></span></td>
									<td><span name="record_gkgg:gonggaor" title="pos||"><span id="3f7a19f0ada0413ea9675508af72b21d" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="10b6e72d4e234c669e0e7708c57ecc2a" class="nlkfqirnlfjerldfgzxcyiuro">06-17</span><span id="17fb4f29c0c34027a5c45f4629923540" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="5e3ef6ed75cb42ee98d67a0cde216054" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="319d4c577ff641b8955a62cdf29c30eb" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="134f3e5ab7b440f9ad958a7e325b500f" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="551c0f5ecb8e40e7a9eb83dad133a2f2" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="87d0ea1098de4c538304349571e07a5a" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="bdc2d4da7f1b40708cc50132bee2e9d7" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="b6f91eb2e5c64024b2ef3f449b4e411c" class="nlkfqirnlfjerldfgzxcyiuro">06-17</span></span></td>
									<td><a href="#"  onclick="downloadPage('/EES41/GKGG/3124/FMSQ3124/FMSQ_DXB/2013101770396','2013101770396')"><i class="download"
											title="下载公告"></i>
									</a></td>
								</tr>
							

						</table>
					</div>
				</div>

				<div class="imfor_part1">
					<h2>
						事务公告
						<i id="swggtitle" class="draw_up"></i>
					</h2>
					<div id="swggid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="40%">事务公告类型</th>
								<th width="30%">公告卷期号</th>
								<th width="30%">事务公告日</th>
							</tr>
							
								<tr>
									<td><span name="record_swgg:shiwugglxdm" title="实质审查请求生效">实质审查请求生效</span></td>
									<td><span name="record_swgg:juanqih" title="29-41">29-41</span></td>
									<td><span name="record_swgg:gonggaor" title="2013-10-09">2013-10-09</span></td>
								</tr>
							
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="ft"></div>

<iframe id="downFrame" frameborder="0" height="0" width="0" ></iframe>

<span style="display: none" id="353160626761313f3e3d6c3d386b396c29777421712d74732d2b2a7a24242f2d0c1817461c1c11164b111f1d4f194d1c08085105040450010e5f0b585d5c580a766d767a7226747e7b287e287a7f7a7c3260333563656f60606e3839646a686c53024e55535600030b0c5d0a5a54575b4717104a12134e451d48194249191b1ce6e7b1afe1e4e3e3edeabcb2eaecbcbda4a7f3a7f5a6a1a7f9f8aeabffa4adfe96c4c096889790c5ca9b9f989f9b9f9ad685d186838d8fd1898a8bd98e8a898bf6f5f1f4a5e9f5a1f8affaaafdfcaca9b2e3e6b7e2b6b4b2baedbbb9bdedbdbcd2d483828680cad2ddde8ed2d5ded68a91c6c6c7c3c7c796c8ccc9989dc4cb9c383034663736602b3f3a3e693f686a3a7273237720217071792a2d22252a7f271314414647101112044812131c1a181f06030b020c01535209580f090e080a0b267373747d2476767c657c2f2a2f7e29353430366c6062363b69383f64383a6f05535302505c525e5c0f460e54590c0a154413464c4646431e4d4c424f451b4be5e4e3b1b2e1b7b0b8eab3a7eabfbbb8f1a3f1f5ada4a5a0acaef8f8a4aaa6fc91c394949195909590989dc98098cc97d186868a8585d080de8dde888584dfdda3f7a6faf7f7fff2acfdffaeafe1f8f7e5e5e3e4b6e2e1e2b9ebeee2e9ebe6e6d48380d687d5d3d3dad9dad38a88c28b95c5cbc396c0c0c09ec0cdcfc5c8cf9e323234663732363e31313e6f343c6f2371227420277026757d282b7e28297f2c1840154515131e151f1e4e1d4f491c181c525102515150550000035959090b5b79797b7777737524717a297c28757f7d317d376265636661396a3f6d6c646a3950595b5406000206595f0f53545f0a0d13125e40104510464c484d434a1f474bb6b7b3bab4e1b0e6ebb9b2babbebecbba5f5a1bff6a1a0a2faabfbafaafcfbfd94989b93c595949e9dca93989bce98c988d3d184988481d1da8ddc8985de8edcf3f5f2f1f3a4f3a4fcfcacfffafff7f6e2e2e7e7e4f9e7e4ecbfe9bee9bcbce882d5d6d382dc8783d1dcd28adb88ddddc593c7c3c493dac2cdc899cb9ac89b9c6239673734603166316c68333f696f6b212221722673243b202e7e2b797c2f2f1919464610461314101a1a1f1f19171a0700570303540356145b5e580e590a5b217624722671767078712928797d7f7c6233373666306f336f75386d3a646f3a02530756075352575a5d0859090b5d0944454b1140104246491a56">版权信息：国家知识产权局所有</span></body>

</html>

HTML;
    public $publication_info_html_b = <<<HTML
<html>
<head>
<title>中国及多国专利审查信息查询</title>
</head>
<body>

<!--导航-->
<input id="usertype" type="hidden"
			value="1">
<div class="hd">
	<div class="head" id="header1">
		<div class="logo_box">
			
		</div>
		<div class="nav_box">
			<ul class="header_menu">
				<li id="header_query" 
					class="_over" ><div
						 class="nav_over" >
						中国专利审查信息查询
					</div></li>
				<li id="header-family" ><div
						>
						多国发明专利审查信息查询
					</div></li>
			</ul>

		</div>
		<div class="hr">
			<ul>
				<!-- 公众用户 -->
				
					<li id="regpublic"><a href="javascript:;">注册</a></li>
					<li id="loginpublic"><a href="javascript:;">登录</a></li>
				
				<!-- 公众注册用户 -->
				
				<!-- 电子申请注册用户 -->
				
				<!-- 公用部分  -->
				
				<li title="选择语言"> 
					<div class="selectlang">
						<a href="javascript:;"> <i class="lang"></i>
						</a>
						<div class="topmenulist hidden">
							<ul>
								<li id="zh"><span  title="中文">中文</span></li>
								<li id="en"><span  title="English">English</span></li>
								<li id="de"><span  title="Deutsch">Deutsch</span></li>
								<li id="es"><span  title="Espa&ntilde;ol">Espa&ntilde;ol</span></li>
								<li id="fr"><span  title="Fran&ccedil;ais">Fran&ccedil;ais</span></li>
								<li id="ja"><span  title="&#26085;&#26412;&#35486;">&#26085;&#26412;&#35486;</span></li>
								<li id="ko"><span  title="&#54620;&#44397;&#50612;">&#54620;&#44397;&#50612;</span></li>
								<li id="ru"><span  title="&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;">&#1056;&#1091;&#1089;&#1089;&#1082;&#1080;&#1081;</span></li>
							</ul>
						</div>
					</div>
				</li>
				<li id="navLogoutBtn" class="mouse_cross" title="退出">
					<a href="javascript:;"><i class="out"></i></a>
			 	</li>
			</ul>
		</div>

		<ul class="float_botton">
		<li id="backToTopBtn" title="返回顶部" style="display: none;"><i
				class="top"></i></li>
			<li id="backToPage" class="hidden"><a href="javascript:;"><i
					class="back" title="返回"></i></a></li>
			<li id="faqBtn" ><a href="javascript:;"><i
					class="faq_icon" title="FAQ"></i></a></li>
		</ul>
	</div>
</div>
<script src='http://cpquery.sipo.gov.cn:80/appjs/header.js'></script>
<!-- header.jsp对应js -->

	<input type='hidden' name='select-key:shenqingh' id='select-key:shenqingh' value="2014205149910">
	<input type='hidden' name='select-key:backPage' id='select-key:backPage' value="http://cpquery.sipo.gov.cn/txnQueryOrdinaryPatents.do?select-key:shenqingh=2014205149910&amp;select-key:zhuanlimc=&amp;select-key:shenqingrxm=&amp;select-key:zhuanlilx=&amp;select-key:shenqingr_from=&amp;select-key:shenqingr_to=&amp;verycode=5&amp;inner-flag:open-type=window&amp;inner-flag:flowno=1508678792948">
	<input type='hidden' name='show:isswggshow' id='show:isswggshow' value="no">
	<input type='hidden' name='show:isgkggshow' id='show:isgkggshow' value="yes">
	<input type='hidden' name='select-key:zhuanlilx' id='select-key:zhuanlilx' value="2">
	<div class="bd">
		<div class="tab_body">
			<div class="tab_list">
				<ul>
					<li id="jbxx" class="tab_first"><div class="tab_top"></div>
						<p>
							申请信息
						</p></li>
					<li id='wjxx'><div class="tab_top"></div>
						<p>
							审查信息
						</p></li>
					<li id='fyxx'><div class="tab_top"></div>
						<p>
							费用信息
						</p></li>
					<li id='fwxx'><div class="tab_top"></div>
						<p>
							发文信息
						</p></li>
					<li id='gbgg' class="on"><div class="tab_top_on"></div>
						<p>
							公布公告
						</p></li>
						
					<li id='djbxx'><div class="tab_top"></div>
						<p>专利登记簿</p></li>
					
				</ul>
			</div>
			<div class="tab_box">
				<div class="imfor_part1">
					<h2>
						发明公布/授权公告
						<i id="gkggtitle" class="draw_up"></i>
					</h2>
					<div id="gkggid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="5%"></th>
								<th width="20%">公告（公布）号</th>
								<th width="25%">公告类型</th>
								<th width="20%">卷期号</th>
								<th width="20%">公告（公布）日</th>
								<th width="10%">操作</th>
							</tr>
							
								<tr>
									<td><input type="checkbox" name="checkbox"
										value="checkbox"></td>
									<td><span name="record_gkgg:gonggaoh" title="pos||"><span id="32a494e9e88343cd8d58d67a05d9b03a" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="b71156e211554945841a7dfb7ec0165a" class="nlkfqirnlfjerldfgzxcyiuro"> 2</span><span id="2030b25f31f541aaa0e18dfab09139a1" class="nlkfqirnlfjerldfgzxcyiuro"> 2</span><span id="c9d9dbf765884b60b64b8235ff949603" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="d845e782b2d04b808130c16e55b03f90" class="nlkfqirnlfjerldfgzxcyiuro">04</span><span id="26ecd06ce0b5400b867c3238989e28bc" class="nlkfqirnlfjerldfgzxcyiuro">21</span><span id="b39749ea9d464075b5b3cac03f128193" class="nlkfqirnlfjerldfgzxcyiuro">21</span><span id="fc003a35ccfc4a3885b85f8dfa5f5589" class="nlkfqirnlfjerldfgzxcyiuro">CN</span><span id="1d4941e80eb84d0682cb57eb1dcbdaa4" class="nlkfqirnlfjerldfgzxcyiuro">5415 U</span><span id="32b7a111d8764d08856686b24699f01b" class="nlkfqirnlfjerldfgzxcyiuro">04</span></span></td>
									<td><span name="record_gkgg:gongkaigglx" title="pos||"><span id="8e189a9463064c38821ac7d5ab275aa4" class="nlkfqirnlfjerldfgzxcyiuro">授</span><span id="d1ac25adb07c453b9f6389769c134869" class="nlkfqirnlfjerldfgzxcyiuro">新</span><span id="08c95dd7b0394cfb8e3ea4d88f004325" class="nlkfqirnlfjerldfgzxcyiuro">新</span><span id="f611edd8a4a349bda659e40b5f50d59f" class="nlkfqirnlfjerldfgzxcyiuro">权公告</span><span id="adb321d7834a4316bb150381bc9c27c3" class="nlkfqirnlfjerldfgzxcyiuro">型</span><span id="f3bd4ea720c248bea0a5d8a2cd737422" class="nlkfqirnlfjerldfgzxcyiuro">型</span><span id="198df26841f74a93a999dc9d897c2291" class="nlkfqirnlfjerldfgzxcyiuro">权公告</span><span id="3db518e360fe4f30a47b5c7edfd1eb29" class="nlkfqirnlfjerldfgzxcyiuro">授</span><span id="f2c03a625224489095de0c9481b08ad4" class="nlkfqirnlfjerldfgzxcyiuro">权公告</span><span id="db4f84bb6bca47e2a92b1b90d701c9f4" class="nlkfqirnlfjerldfgzxcyiuro">授</span></span></td>
									<td><span name="record_gkgg:juanqih" title="pos||"><span id="ac44d0bc175843baa756c10d6b477549" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="4669afb737b6478a835b94333475173f" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="d9fba6b5fd9b483ab1483c8add31b62b" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="fd5fd852de7b41d280f8e1874a5d1c8a" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="aef8a2ad1091477eb9e30dd2cac1be79" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="ea1f4d8e4ca64873a9f451535c34f905" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="c4a4ed3245324010a2dfafe8d589dee3" class="nlkfqirnlfjerldfgzxcyiuro">-11</span><span id="b91595d098b141f189f8c3bea2604d65" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="517d404bbeaf4f8799cf95ee58899c94" class="nlkfqirnlfjerldfgzxcyiuro">31</span><span id="602d5ac276bc4354ad574b2b90d6c689" class="nlkfqirnlfjerldfgzxcyiuro">-11</span></span></td>
									<td><span name="record_gkgg:gonggaor" title="pos||"><span id="a2141a281a8f447e84fa614c600ba0d4" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="b36ea7bb4ff5404782f4f34a2263334f" class="nlkfqirnlfjerldfgzxcyiuro">2</span><span id="46753c9ae4b44bc4b2f8c2d48bd6482b" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="5f02bf40988a4a71a95c8e7bd1a71dde" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="bd42f2624c7c43ceb4085a3e08730f5b" class="nlkfqirnlfjerldfgzxcyiuro">03-18</span><span id="8173effb4cf14686a04eff5eb1c99c9f" class="nlkfqirnlfjerldfgzxcyiuro">0</span><span id="340edf6e8b9542bf92bf48c5492992ae" class="nlkfqirnlfjerldfgzxcyiuro">1</span><span id="c50f3d7bb4f94502886c9c5aa69a4dcb" class="nlkfqirnlfjerldfgzxcyiuro">5</span><span id="339b807dd0644253addd3b77907fccb1" class="nlkfqirnlfjerldfgzxcyiuro">-</span><span id="65403e5bf7bb436fa4c92a163836c7dc" class="nlkfqirnlfjerldfgzxcyiuro">03-18</span></span></td>
									<td><a href="#"  onclick="downloadPage('/EES38/GKGG/3111/XXSQ3111/XXSQ_DXB/2014205149910','2014205149910')"><i class="download"
											title="下载公告"></i>
									</a></td>
								</tr>
							

						</table>
					</div>
				</div>

				<div class="imfor_part1">
					<h2>
						事务公告
						<i id="swggtitle" class="draw_up"></i>
					</h2>
					<div id="swggid" class="imfor_table">
						<table class="imfor_table_grid">
							<tr>
								<th width="40%">事务公告类型</th>
								<th width="30%">公告卷期号</th>
								<th width="30%">事务公告日</th>
							</tr>
							
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="ft"></div>

<iframe id="downFrame" frameborder="0" height="0" width="0" ></iframe>


<span style="display: none" id="333363373d31633e6d313238383e6d6b2875272b70232176282c7e227e2d2d7e0c131210144714124e1a1b4d19191f4e51500256050d5251595b0a020d0e075e716d267b70702370707b2879287d7a2d68616a62676535666e3c6f6e3e6d6d3959514e5152000503585f090e5c0f5b5b4041104b424215444a4a424244441b4db8e3e1afb5e1b2bebcb8efb3bce8ecb7a4f5a2a5aca7f5f5adaefff9adf9fdfdc4c0c39788959ec4919ccecf9bcf9e9c8985d1d5d68dd384ddd88edf8485d88ff0f5f1f1f1e9a0f4aaadfeaeadfafcffb3e3e6ebb6b0b7e7b9ecbee3bdefbdbbd7d2d5d7d6d7cad48c8bdfdad488ddd9c09797c792c6c696ccce98ce9fca9b9b6665336666373f2b6e3b693b3f6c383d25232027202d2f27212c7e7e2c7e272b181040131c444213041c1b1c48191e1b525357525201500f0f0003585a040b5a25747a7b7d7c257e7c657c7b7e297b2e33636565363662646d6d3b3f696a6a3d52035b5300530551505046095f5b0b0e4713104712134343484d4d434e1b4a19b3b5e3b1b6b3b5b4bbbdeca7b9ebbebdf2f7a6a3adadaef6acf8adaafda4abfc98c495c1c094c79099cdcece809e9a9fd5d5d485d18dd48e8d8d88d9da848cdda6f5faa0f1f1fff5f1f0f8aaa9e1adfae0b7e1b7e3b7b4e3bee0eeeeecefe6e7d682db80d18487d1d188de8f8f8fc2dcc3c890cbc4c29293c8cfcecfcec8cd9e646566306632313e383e6c686f6f3f2326242623277023757e2e7879282e28794115411a164417111b11191d4f1a4a4c1c">版权信息：国家知识产权局所有</span></body>

</html>

HTML;



}