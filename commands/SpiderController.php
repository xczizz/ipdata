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

            $randomSeconds = mt_rand(1,3);
            sleep($randomSeconds);

        } while (!empty($this->queue));

        $this->queue = Patent::find()->select(['application_no'])->asArray()->all();
        do {
            $patents_list = [];
            for ($i = 0; $i < 5 ; $i++) {
                $patents_list[] = array_shift($this->queue);
            }
            $this->crawlPublicationInfo(array_filter($patents_list));

            $randomSeconds = mt_rand(1,3);
            sleep($randomSeconds);

        } while (!empty($this->queue));

        $this->queue = Patent::find()->select(['application_no'])->asArray()->all();
        do {
            $patents_list = [];
            for ($i = 0; $i < 5 ; $i++) {
                $patents_list[] = array_shift($this->queue);
            }
            $this->crawlPaymentInfo(array_filter($patents_list));

            $randomSeconds = mt_rand(1,3);
            sleep($randomSeconds);

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
                        $result = $this->parsePaymentInfo($html, $patent_list[$index]['application_no']);
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
     * @param $application_no
     * @return array
     */
    public function parsePaymentInfo($html, $application_no)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $last_span = $crawler->filter('body > span')->last();
        if (!$last_span->count()) {
            $this->stdout('Error: empty node'.$application_no.PHP_EOL.'Source code: '.$html);
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
                        $result = $this->parseBasicInfo($html, $patent_list[$index]['application_no']);
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
     * @param $application_no
     * @return array
     */
    public function parseBasicInfo($html, $application_no)
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

            $this->stdout('Error: empty node '. $application_no .PHP_EOL.'Source code: '.$html);
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
        $model->updated_at = time();
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
                        $result = $this->parsePublicationInfo($html, $patent_list[$index]['application_no']);
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
     * @param $application_no
     * @return array
     */
    public function parsePublicationInfo($html, $application_no)
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent($html);
        $last_span = $crawler->filter('body > span')->last();
        // 发明公布/授权公告
        $publication = [
            'publication_date' => null,
            'publication_no' => null,
            'issue_announcement' => null,
            'issue_no' => null
        ];
        if (!$last_span->count()) {

            $this->stdout('Error: empty node'. $application_no . PHP_EOL.'Source code: '.$html);
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
                    // 授权公告号
                    $publication_no = $trCrawler->filter('span[name="record_gkgg:gonggaoh"] span')->each(
                        function (Crawler $node) use ($useful_id) {
                            if (isset($useful_id[$node->attr('id')])) {
                                return $node->text();
                            }
                        }
                    );
                    $publication_no = str_replace(' ','',implode('', $publication_no));
                    $publication['issue_no'] = $publication_no;

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
        $model->issue_no = $data['issue_no'];
        $model->updated_at = time();
        $model->save();
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
     * 使用并发的时候如果获取id失败则使用单页爬取
     *
     * @param String $application_no
     * @param String $info_type
     * @return String
     */
    public function single(String $application_no, String $info_type): String
    {
        //$info_type
        $base_uri = [
            'basic' => 'http://cpquery.sipo.gov.cn/txnQueryBibliographicData.do?select-key:shenqingh=', //基本信息
            'fee' =>  'http://cpquery.sipo.gov.cn/txnQueryFeeData.do?select-key:shenqingh=', //fee
            'publication' => 'http://cpquery.sipo.gov.cn/txnQueryPublicationData.do?select-key:shenqingh=', //公告信息
        ];
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

        $response = $client->request('GET', $base_uri[$info_type] . $application_no);

        $html = $response->getBody();

        return $html;
    }

    /**
     * 单个爬取第一遍不能爬到的申请号
     */
    public function actionSingle()
    {
        $start = $_SERVER['REQUEST_TIME'];  // 开始时间
        $this->stdout('Start time:' . date('H:i:s',$start) . PHP_EOL);

        $application_no_s = Yii::$app->db
            ->createCommand(
            'SELECT application_no FROM patent WHERE title is null OR title=""'
        )->queryColumn();

        foreach ($application_no_s as $application_no)
        {
            $basic_html = $this->single($application_no, 'basic');
            $result = $this->parseBasicInfo($basic_html, $application_no);
            $this->saveBasicInfo($result, $application_no);


            $fee_html = $this->single($application_no, 'fee');
            $result = $this->parsePaymentInfo($fee_html, $application_no);
            $this->savePaymentInfo($result, $application_no);

            $publication_html = $this->single($application_no, 'publication');
            $result = $this->parsePublicationInfo($publication_html, $application_no);
            $this->savePublicationInfo($result, $application_no);

            $randomSeconds = mt_rand(1,3);
            sleep($randomSeconds);
        }

        $this->stdout('Voila'. PHP_EOL);
        $this->stdout('Time Consuming:' . (time() - $start) . ' seconds' . PHP_EOL);
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
}