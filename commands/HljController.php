<?php
/**
 * User: Mr-mao
 * Date: 2017/10/28
 * Time: 11:04
 */

namespace app\commands;

use yii\console\Controller;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

class HljController extends Controller
{
    public function actionIndex()
    {
        $total = 19439;
        $pagerecord = 500; // 最多500

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
                    // 'Cookie' => 'tencentSig=4818557952; JSESSIONID='.$this->getJessionID().'; _qddac=4-3-1.1d0eof.1912wz.j9aobqjv; _qddaz=QD.xuzlxr.ai4dpc.j8fo6daj; _gscu_1547464065=043244422dtr4j90; IESESSION=alive; _qddamta_4001880860=4-0; _qdda=4-1.1d0eof; _qddab=4-1912wz.j9aobqjv', // _qddab 每天不一样
                    'Cookie' => 'tencentSig=4818557952; JSESSIONID=2E090CAC50D86904B5E291CA23FADAD9; _qddaz=QD.xuzlxr.ai4dpc.j8fo6daj; _gscu_1547464065=043244422dtr4j90; IESESSION=alive; _qddamta_4001880860=4-0; _qdda=4-1.1d0eof; _qddab=4-1912wz.j9aobqjv',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.90 Safari/537.36'
                ],
                'form_params' => [
                    'area' => 'cn',
                    'strWhere' => '申请（专利权）人=(哈尔滨工业大学)',
                    'strSynonymous' => 'SYNONYM_UTF8',
                    'strSortMethod' => 'RELEVANCE',
                    'strDefautCols' => '主权项, 名称',
                    'iHitPointType' => 115,
                    'strChannels' => '14,15,16',
                    'searchKind' => 'tableSearch',
                    'trsLastWhere' => null,
                    'ABSTBatchCount' => 0,
                    'strSources' => 'fmzl_ft,syxx_ft,wgzl_ab',
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
                echo (new Crawler($value))->filter('input')->attr('an');
                // 有效 无效
                $status = new Crawler();
                $status->addHtmlContent($value);
                echo $status->filter('.checkbox > span')->last()->html().PHP_EOL;
            }
        }
    }

    public function getJsessionID()
    {
        $ch = curl_init('http://db.hlipo.gov.cn:8080/ipsss/showSearchForm.do?area=cn');
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