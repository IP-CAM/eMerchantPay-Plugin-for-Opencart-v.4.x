<?php
/**
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author      emerchantpay
 * @copyright   Copyright (C) 2015-2024 emerchantpay Ltd.
 * @license     http://opensource.org/licenses/MIT The MIT License
 */
namespace Genesis\API;

use \DateTime;
use Genesis\Network;
use Genesis\Parser;
use Genesis\API\Request\NonFinancial\Reconcile\DateRange;
use Genesis\API\Request\NonFinancial\Reconcile\Transaction;
use Genesis\API\Request\WPF\Reconcile;
use Genesis\Exceptions\ErrorAPI;
use Genesis\Exceptions\InvalidArgument;
use Genesis\Exceptions\InvalidResponse;

/**
 * Response - process/format an incoming Genesis response
 *
 * @package    Genesis
 * @subpackage API
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Response
{
    const HEADER_CONTENT_TYPE_JSON = 'Content-Type: application/json';

    /**
     * Store parsed, response object
     *
     * @var \stdClass
     */
    public $responseObj;

    /**
     * Store the response raw data
     *
     * @var String
     */
    public $responseRaw;

    /**
     * Genesis Request Context
     *
     * @var \Genesis\API\Request
     */
    protected $requestCtx;

    /**
     * Initialize with NetworkContext (if available)
     *
     * @param \Genesis\Network|null $networkContext
     *
     * @throws \Genesis\Exceptions\InvalidArgument
     */
    public function __construct($networkContext = null)
    {
        if (!is_null($networkContext) && is_a($networkContext, '\Genesis\Network')) {
            $this->parseResponse($networkContext);
        }
    }

    /**
     * Parse Genesis response to stdClass and
     * apply transformation to known fields
     *
     * @param Network $network
     *
     * @throws \Genesis\Exceptions\ErrorAPI
     * @throws \Genesis\Exceptions\InvalidArgument
     * @throws \Genesis\Exceptions\InvalidResponse
     */
    public function parseResponse(Network $network)
    {
        $this->responseRaw = $network->getResponseBody();

        try {
            $parser = $this->getParser($network);

            $this->responseObj = $parser->getObject();
        } catch (\Exception $e) {
            throw new InvalidResponse(
                $e->getMessage(),
                $e->getCode()
            );
        }

        // Apply per-field transformations
        $this->transform([$this->responseObj]);

        if (isset($this->responseObj->status) && is_string($this->responseObj->status)) {
            $state = new Constants\Transaction\States($this->responseObj->status);

            if (!$state->isValid()) {
                throw new InvalidArgument(
                    'Unknown transaction status',
                    isset($this->responseObj->code) ? $this->responseObj->code : 0
                );
            }

            if ($state->isError() && !$this->suppressReconciliationException()) {
                throw new ErrorAPI(
                    $this->responseObj->message,
                    isset($this->responseObj->code) ? $this->responseObj->code : 0
                );
            }
        }
    }

    protected function getParser(Network $network)
    {
        if ($this->isResponseTypeJson($network->getResponseHeaders())) {
            $parser = new Parser(Parser::JSON_INTERFACE);
            $parser->parseDocument($network->getResponseBody());

            return $parser;
        }

        $parser = new Parser(Parser::XML_INTERFACE);
        $parser->skipRootNode();
        $parser->parseDocument($network->getResponseBody());

        return $parser;
    }

    /**
     * @param string $headers
     *
     * @return bool
     */
    protected function isResponseTypeJson($headers)
    {
        return stripos($headers, self::HEADER_CONTENT_TYPE_JSON) !== false;
    }

    /**
     * Check whether the request was successful
     *
     * Note: You should consult with the documentation
     * which transaction responses have status available.
     *
     * @return bool | null (on missing status)
     */
    public function isSuccessful()
    {
        $status = new Constants\Transaction\States(
            isset($this->responseObj->status) ? $this->responseObj->status : ''
        );

        if ($status->isValid()) {
            return !$status->isError();
        }

        return null;
    }

    /**
     * Check whether the transaction was partially approved
     *
     * @see Genesis_API_Documentation for more information
     *
     * @return bool | null (if inapplicable)
     */
    public function isPartiallyApproved()
    {
        if (isset($this->responseObj->partial_approval)) {
            return \Genesis\Utils\Common::filterBoolean($this->responseObj->partial_approval);
        }

        return null;
    }

    /**
     * Suppress Reconciliation responses as their statuses
     * reflect their transactions
     *
     * @return bool
     */
    public function suppressReconciliationException()
    {
        $instances = [
            new DateRange(),
            new Transaction(),
            new Reconcile()
        ];

        if (isset($this->requestCtx) && isset($this->responseObj->unique_id)) {
            foreach ($instances as $instance) {
                if ($this->requestCtx instanceof $instance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Try to fetch a description of the received Error Code
     *
     * @return string | null (if inapplicable)
     */
    public function getErrorDescription()
    {
        if (isset($this->responseObj->code) && !empty($this->responseObj->code)) {
            return Constants\Errors::getErrorDescription($this->responseObj->code);
        }

        if (isset($this->responseObj->response_code) && !empty($this->responseObj->response_code)) {
            return Constants\Errors::getIssuerResponseCode($this->responseObj->response_code);
        }

        return null;
    }

    /**
     * Get the raw Genesis output
     *
     * @return String
     */
    public function getResponseRaw()
    {
        return $this->responseRaw;
    }

    /**
     * Get the parsed response
     *
     * @return \stdClass
     */
    public function getResponseObject()
    {
        return $this->responseObj;
    }

    /**
     * Set Genesis Request context
     *
     * @param $requestCtx
     */
    public function setRequestCtx($requestCtx)
    {
        $this->requestCtx = $requestCtx;
    }

    /**
     * Iterate and transform object
     *
     * @param mixed $obj
     */
    public static function transform($obj)
    {
        if (is_array($obj) || is_object($obj)) {
            foreach ($obj as &$object) {
                if (isset($object->status)) {
                    self::transformObject($object);
                }

                self::transform($object);
            }
        }
    }

    /**
     * Apply filters to an entry object
     *
     * @param \stdClass|\ArrayObject $entry
     *
     * @return mixed
     */
    public static function transformObject(&$entry)
    {
        $filters = [
            'transformFilterAmounts',
            'transformFilterTimestamp'
        ];

        foreach ($filters as $filter) {
            if (method_exists(__CLASS__, $filter)) {
                $result = call_user_func([__CLASS__, $filter], $entry);

                if ($result) {
                    $entry = $result;
                }
            }
        }
    }

    /**
     * Get formatted response amounts (instead of ISO4217, return in float)
     *
     * @param \stdClass|\ArrayObject $transaction
     *
     * @return \stdClass|\ArrayObject $transaction
     */
    public static function transformFilterAmounts($transaction)
    {
        $properties = [
            'amount',
            'leftover_amount'
        ];

        foreach ($properties as $property) {
            if (isset($transaction->{$property}) && isset($transaction->currency)) {
                $transaction->{$property} = \Genesis\Utils\Currency::exponentToAmount(
                    $transaction->{$property},
                    $transaction->currency
                );
            }
        }
        return $transaction;
    }

    /**
     * Get formatted amount (instead of ISO4217, return in float)
     *
     * @param \stdClass|\ArrayObject $transaction
     *
     * @return String | null (if amount/currency are unavailable)
     */
    public static function transformFilterAmount($transaction)
    {
        // Process a single transaction
        if (isset($transaction->currency) && isset($transaction->amount)) {
            $transaction->amount = \Genesis\Utils\Currency::exponentToAmount(
                $transaction->amount,
                $transaction->currency
            );
        }
        return $transaction;
    }

    /**
     * Get DateTime object from the timestamp inside the response
     *
     * @param \stdClass|\ArrayObject $transaction
     *
     * @return \DateTime|null (if invalid timestamp)
     */
    public static function transformFilterTimestamp($transaction)
    {
        if (isset($transaction->timestamp)) {
            try {
                $transaction->timestamp = new DateTime($transaction->timestamp);
            } catch (\Exception $e) {
                // Just log the attempt
                error_log($e->getMessage());
            }
        }

        return $transaction;
    }
}
