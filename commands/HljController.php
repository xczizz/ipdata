<?php
/**
 * User: Mr-mao
 * Date: 2017/10/28
 * Time: 11:04
 */

namespace app\commands;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class HljController extends BaseController
{
    public function actionIndex()
    {
        $total = 19500;
        $pagerecord = 300; // 最多500
        $successCount = 0;

        echo 'Start time: ' . date('y/m/d H:i:s') . PHP_EOL;
        for ($i=1; $i <= ceil($total / $pagerecord); $i++) {
            $client = new Client();
            $options = [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Referer' => 'http://db.hlipo.gov.cn:8080/ipsss/showSearchForm.do?area=cn',
                    'Origin' => 'http://db.hlipo.gov.cn:8080',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.8',
                    'Connection' => 'keep-alive',
                    'Cookie' => 'JSESSIONID='.$this->getJsessionID().';',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36'
                ],
                'form_params' => [
                    'area' => 'cn',
                    'searchKind' => 'tableSearch',
//                    'strChannels' => 'fmzl_ft,syxx_ft,wgzl_ab,fmsq_ft',
                    'strChannels' => '14,15,16,17',
                    'strSynonymous' => 'SYNONYM_UTF8',
                    'ABSTBatchCount' => 0,
                    'strWhere' => '申请（专利权）人=(哈尔滨工业大学)',
                    'strSources' => 'fmzl_ft,syxx_ft,wgzl_ab,fmsq_ft',
                    'strSortMethod' => 'RELEVANCE',
//                    'synonymous' => 'SYNONYM_UTF8',
                    'strDefautCols' => '主权项, 名称, 摘要',
                    'iHitPointType' => 115,
                    'trsLastWhere' => null,
                    'iOption' => 2,
                    'pageIndex' => $i,
                    'pagerecord' => $pagerecord
                ],
            ];
            $response = $client->request('POST', 'http://db.hlipo.gov.cn:8080/ipsss/overviewSearch.do?area=cn', $options);
            $html = $response->getBody();
            // echo $html;
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
                if ($rows) $successCount += 1;
            }
        }
        echo 'End time: ' . date('y/m/d H:i:s') . PHP_EOL . 'Write successful: ' . $successCount;
    }

    /**
     * 获取登录之后的sessionid
     *
     * @return mixed
     */
    private function getJsessionID()
    {
        $ch = curl_init('http://db.hlipo.gov.cn:8080/ipsss/login.do?' . rawurlencode('timeStamp='.date('D M d Y H:i:s')) .'GMT+0800'. rawurlencode(' (中国标准时间)') . '&username=guest&password=123456&totalPages=0');
        curl_setopt($ch, CURLOPT_REFERER, "http://db.hlipo.gov.cn:8080/ipsss/");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['host' => 'http://db.hlipo.gov.cn:8080']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // get headers too with this line
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $result = curl_exec($ch);
        // get cookie
        // multi-cookie variant contributed by @Combuster in comments
        preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $result, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        return $cookies['JSESSIONID'];
    }
}
