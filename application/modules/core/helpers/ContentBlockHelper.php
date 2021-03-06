<?php
namespace app\modules\core\helpers;

use app\modules\core\models\ContentBlock;
use devgroup\TagDependencyHelper\ActiveRecordHelper;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use yii;
use app;

/**
 * Class ContentBlockHelper
 * Main public static method compileContentString() uses submethods to extract chunk calls from model content field,
 * fetch chunks from data base table, then compile it and replace chunk calls with compiled chunks data
 * Example chunk call in model content field should be like: [[$chunk param='value'|'default value' param2=42]].
 * Chunk declaration should be like : <p>String: [[+param]]</p> <p>Float: [[+param2:format, param1, param2]]</p>
 * All supported formats you can find at Yii::$app->formatter
 *
 * @package app\modules\core\helpers
 */
class ContentBlockHelper
{
    private static $chunksByKey = [];

    /**
     * Compiles content string by injecting chunks into content
     * Preloads chunks which have preload = 1
     * Finding chunk calls with regexp
     * Iterate matches
     * While iterating:
     * Extracts single chunk data with sanitizeChunk() method
     * Fetches chunk by key using fetchChunkByKey(), who returns chunk value by key from static array if exists, otherwise from db
     * Compiles single chunk using compileChunk() method
     * Replaces single chunk call with compiled chunk data in the model content
     *
     * @param  {string} $content Original content with chunk calls
     * @param  {string} $content_key Key for caching compiled content version
     * @param  {yii\caching\Dependency} $dependency  Cache dependency
     * @return {string} Compiled content with injected chunks
     */
    public static function compileContentString($content, $content_key, $dependency)
    {
        self::preloadChunks();
        $matches = [];
        preg_match_all('%\[\[([^\]\[]+)\]\]%ui', $content, $matches);
        if (!empty($matches)) {
            foreach ($matches[0] as $k => $rawChunk) {
                $chunkData = static::sanitizeChunk($rawChunk);
                $cacheChunkKey = $chunkData['key'] . $content_key;
                $replacement = Yii::$app->cache->get($cacheChunkKey);
                if ($replacement === false) {


                    switch ($chunkData['token']) {
                        case '$':
                            $chunk = self::fetchChunkByKey($chunkData['key']);
                            $replacement = static::compileChunk($chunk, $chunkData);
                            if (null !== $chunk) {
                                Yii::$app->cache->set(
                                    $cacheChunkKey,
                                    $replacement,
                                    84600,
                                    $dependency
                                );
                            }
                            break;
                        case '%':
                            $replacement = static::replaceForms($chunkData);
                            break;
                        default:
                            $replacement = '';
                    }

                }
                $content = str_replace($matches[0][$k], $replacement, $content);
            }
        }
        return $content;
    }

    public static function replaceForms($chunkData)
    {
        $regexp = '/^(?P<formId>\d+)(#(?P<id>[\w\d\-_]+))?(;(?P<isModal>isModal))?$/Usi';
        return preg_replace_callback(
            $regexp,
            function($matches) {
                if (isset($matches['formId'])) {
                    $params = ['formId' => intval($matches['formId'])];
                    if (isset($matches['id'])) {
                        $params['id'] = $matches['id'];
                    }
                    if (isset($matches['isModal'])) {
                        $params['isModal'] = true;
                    }
                    return app\widgets\form\Form::widget($params);
                }
                return '';
            },
            $chunkData['key']
        );
    }

    /**
     * Extracts chunk data from chunk call
     * uses regexp to extract param data from placeholder
     * [[$chunk <paramName>='<escapedValue>'|'<escapedDefault>' <paramName>=<unescapedValue>|<unescapedDefault>]]
     * iterate matches.
     * While iterating converts escapedValue and escapedDefault into string, unescapedValue and unescapedDefault - into float
     * Returns chunk data array like:
     *  [
     *      'key' => 'chunkKey',
     *      'firstParam'=> 'string value',
     *      'firstParam-default'=> 'default string value',
     *      'secondParam'=> float value,
     *      'secondParam-default'=> default float value,
     *  ]
     *
     * @param $rawChunk
     * @return array
     */
    private static function sanitizeChunk($rawChunk)
    {
        $chunk = [];
        preg_match('%(?P<chunkToken>[^\w\[]?)([^\s\]\[]+)[\s\]]%', $rawChunk, $keyMatches);
        $chunk['token'] = $keyMatches['chunkToken'];
        $chunk['key'] = $keyMatches[2];
        $expression = "#\s*(?P<paramName>[\\w\\d]*)=(('(?P<escapedValue>.*[^\\\\])')|(?P<unescapedValue>.*))(\\|(('(?P<escapedDefault>.*[^\\\\])')|(?P<unescapedDefault>.*)))?[\\]\\s]#uUi";
        preg_match_all($expression, $rawChunk, $matches);
        foreach ($matches['paramName'] as $key => $paramName) {
            if (!empty($matches['escapedValue'][$key])) {
                $chunk[$paramName] = strval($matches['escapedValue'][$key]);
            }
            if (!empty($matches['unescapedValue'][$key])) {
                $chunk[$paramName] = floatval($matches['unescapedValue'][$key]);
            }
            if (!empty($matches['escapedDefault'][$key])) {
                $chunk[$paramName . '-default'] = strval($matches['escapedDefault'][$key]);
            }
            if (!empty($matches['unescapedDefault'][$key])) {
                $chunk[$paramName . '-default'] = floatval($matches['unescapedDefault'][$key]);
            }
        }
        return $chunk;
    }

    /**
     * @param  {ContentBlock} $chunk     ContentBlock instance
     * @param  {array} $arguments Arguments for this chunk from original content
     * @return {string} Result string ready for replacing
     *
     * Compiles single chunk
     * uses regexp to find placeholders and extract it's data from chunk value field
     * [[<token><paramName>:<format><params>]]
     * token switch is for future functionality increase
     * now method only recognizes + token and replaces following param with according $arguments array data
     * applies formatter according previously defined param values type if needed
     * if param name from placeholder was not found in arguments array, placeholder in the compiled chunk will be replaced with empty string
     * returns compiled chunk
     */
    public static function compileChunk($chunk, $arguments)
    {
        $matches = [];
        preg_match_all('%\[\[(?P<token>[\+\*\%])(?P<paramName>[^\s\:\]]+)\:?(?P<format>[^\,\]]+)?\,?(?P<params>[^\]]+)?\]\]%ui', $chunk, $matches);
        foreach ($matches[0] as $k => $rawParam) {
            $token = $matches['token'][$k];
            $paramName = trim($matches['paramName'][$k]);
            $format = trim($matches['format'][$k]);
            $params = preg_replace('%[\s]%', '', $matches['params'][$k]);
            $params = explode(',', $params);
            switch ($token) {
                case '+':
                    if (array_key_exists($paramName, $arguments)) {
                        $replacement = static::applyFormatter($arguments[$paramName], $format, $params);
                        $chunk = str_replace($matches[0][$k], $replacement, $chunk);
                    } else if (array_key_exists($paramName . '-default', $arguments)) {
                        $replacement = static::applyFormatter($arguments[$paramName . '-default'], $format, $params);
                        $chunk = str_replace($matches[0][$k], $replacement, $chunk);
                    } else {
                        $chunk = str_replace($matches[0][$k], '', $chunk);
                    }
                    break;
                default:
                    $chunk = str_replace($matches[0][$k], '', $chunk);
            }
        }
        return $chunk;
    }

    /**
     * Find formatter declarations in chunk placeholders. if find trying to apply
     * yii\i18n\Formatter formats see yii\i18n\Formatter for details
     * @param {string} $rawParam single placeholder declaration from chunk
     * @param $format {string}
     * @param $params {array}
     * @return array
     */
    private static function applyFormatter($value, $format, $params)
    {
        if (false === method_exists(Yii::$app->formatter, $format) || empty($format)) {
            return $value;
        }
        array_unshift($params, $value);
        try{
            $formattedValue = call_user_func_array([Yii::$app->formatter, $format], $params);
        } catch (\Exception $e) {
            $formattedValue = $value;
        }
        return $formattedValue;
    }

    /**
     * Fetches single chunk by key from static var
     * if is no there - get it from db and push to static array
     * @param $key {string} Chunk key field
     * @return {string} Chunk value field
     */
    public static function fetchChunkByKey($key)
    {
        if (!array_key_exists($key, static::$chunksByKey)) {
            $dependency = new TagDependency([
                'tags' => [
                    ActiveRecordHelper::getCommonTag(ContentBlock::className()),
                ]
            ]);
            static::$chunksByKey[$key] = ContentBlock::getDb()->cache(function($db) use ($key) {
                $chunk = ContentBlock::find()
                    ->where(['key' => $key])
                    ->asArray()
                    ->one();
                return static::$chunksByKey[$key] = $chunk['value'];
            }, 86400, $dependency);
        }
        return static::$chunksByKey[$key];
    }

    /**
     * preloads chunks with option preload  = 1
     * and push it to static array
     * @return array|void
     */
    public static function preloadChunks()
    {
        if (is_null(static::$chunksByKey)) {
            $dependency = new TagDependency([
                'tags' => [
                    ActiveRecordHelper::getCommonTag(ContentBlock::className()),
                ]
            ]);
            static::$chunksByKey = ContentBlock::getDb()->cache(function ($db) {
                $chunks = ContentBlock::find()
                    ->where(['preload' => 1])
                    ->asArray()
                    ->all();
                return ArrayHelper::map($chunks, 'key', 'value');
            }, 86400, $dependency);
        }
        return static::$chunksByKey;
    }
}
