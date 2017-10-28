<?php
namespace app\swagger;

/**
 * @SWG\Swagger(
 *     schemes={"http"},
 *     host="api.ipapp.com",
 *     basePath="/",
 *     produces={"application/json", "application/xml"},
 *     @SWG\Info(
 *         version="1.0.0",
 *         title="REST APIs for shineip",
 *         description="Version: __1.0.0__",
 *     ),
 * )
 *
 * @SWG\Tag(
 *   name="Patent",
 *   description="专利相关",
 *   @SWG\ExternalDocumentation(
 *     description="Find out more about our store",
 *     url="http://swagger.io"
 *   )
 * )
 */

/**
 * @SWG\Definition(
 *   @SWG\Xml(name="##default")
 * )
 */
class ApiResponse
{
    /**
     * @SWG\Property(format="int32", description = "code of result")
     * @var int
     */
    public $code;
    /**
     * @SWG\Property
     * @var string
     */
    public $type;
    /**
     * @SWG\Property
     * @var string
     */
    public $message;
    /**
     * @SWG\Property(format = "int64", enum = {1, 2})
     * @var integer
     */
    public $status;
}