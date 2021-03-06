<?php

namespace miolae\billing;

use miolae\billing\exceptions\TransactionException;
use miolae\billing\helpers\EventHelper;
use miolae\billing\models\Account;
use miolae\billing\models\Invoice;
use miolae\billing\models\Transaction;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Module as BaseModule;
use yii\db\ActiveRecord;
use yii\db\Connection;
use yii\db\Transaction as DBTransaction;

/**
 * Class Module
 *
 * @package miolae\billing
 *
 * @property-read Connection|null db
 */
class Module extends BaseModule
{
    /** @var int BlackHole accounts will always have zero in their amount */
    const BLACK_HOLE_ZERO = 0;
    /**
     * @var int BlackHole accounts will have endless amount and won't be checked for correct amount on saving.
     * Held count will always be a zero.
     */
    const BLACK_HOLE_ENDLESS = 1;

    /** @var array $modelMapDefault */
    protected $modelMapDefault = [
        'Invoice'     => Invoice::class,
        'Account'     => Account::class,
        'Transaction' => Transaction::class,
        'EventHelper' => EventHelper::class,
    ];

    /** @var ActiveRecord[] Model map */
    public $modelMap = [];

    /** @var string DB connection name */
    public $dbConnection = 'db';

    public $blackHoleStrategy = self::BLACK_HOLE_ZERO;

    public function init()
    {
        parent::init();
        $this->mapModels();
        $this->eventsAttach();
    }

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function getDb(): ?Component
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return \Yii::$app->get($this->dbConnection);
    }

    /**
     * @param int|ActiveRecord $accountFrom
     * @param int|ActiveRecord $accountTo
     * @param                  $amount
     *
     * @return Invoice|ActiveRecord
     */
    public function createInvoice($accountFrom, $accountTo, float $amount): ActiveRecord
    {
        if (is_subclass_of($accountFrom, ActiveRecord::class)) {
            $accountFrom = $accountFrom->id;
        }

        if (is_subclass_of($accountTo, ActiveRecord::class)) {
            $accountTo = $accountTo->id;
        }

        $accountFrom = (int)$accountFrom;
        $accountTo = (int)$accountTo;

        $attributes = [
            'amount'          => $amount,
            'account_id_from' => $accountFrom,
            'account_id_to'   => $accountTo,
        ];

        /** @var ActiveRecord $invoice */
        $invoice = new $this->modelMap['Invoice']($attributes);
        $invoice->save();

        return $invoice;
    }

    /**
     * Hold funds on accountFrom of the given invoice
     *
     * @param ActiveRecord $invoice
     *
     * @return bool
     *
     * @throws TransactionException
     * @throws \yii\db\Exception
     */
    public function hold(ActiveRecord $invoice): bool
    {
        /** @var Invoice $invoice */
        /** @var Transaction $transactionClass */
        $transactionClass = $this->modelMap['Transaction'];

        if ($invoice->status !== $invoice::STATUS_CREATE) {
            $invoice->addError('status', 'Invoice must be in "CREATED" status when holding');

            return false;
        }

        $attributes = [
            'invoice_id' => $invoice->id,
            'type' => $transactionClass::TYPE_HOLD,
        ];

        /** @var Transaction $transaction */
        $transaction = $transactionClass::create($attributes);

        $dbTransact = $this->db->beginTransaction();

        $invoice->accountFrom->hold += $invoice->amount;
        if (!static::saveModel($invoice->accountFrom, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $invoice->status = $invoice::STATUS_HOLD;
        if (!static::saveModel($invoice, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $transaction->success();
        $dbTransact->commit();

        return true;
    }

    /**
     * Finish transferring funds for a holded invoice
     *
     * @param Invoice $invoice invoice in status HOLD
     *
     * @return bool
     * @throws InvalidConfigException
     * @throws TransactionException
     * @throws \yii\db\Exception
     */
    public function finish(ActiveRecord $invoice): bool
    {
        if (!$invoice instanceof $this->modelMap['Invoice']) {
            throw new InvalidConfigException('Invoice must be a class of ' . $this->modelMap['Invoice']);
        }

        if ($invoice->status !== $invoice::STATUS_HOLD) {
            $invoice->addError('status', 'Invoice must be in "HOLD" status when finishing');

            return false;
        }

        /** @var Transaction $transactionClass */
        $transactionClass = $this->modelMap['Transaction'];
        $attributes = [
            'invoice_id' => $invoice->id,
            'type' => $transactionClass::TYPE_FINISH,
        ];
        /** @var Transaction $transaction */
        $transaction = $transactionClass::create($attributes);

        $dbTransact = $this->db->beginTransaction();

        $invoice->accountFrom->amount -= $invoice->amount;
        $invoice->accountFrom->hold -= $invoice->amount;
        if (!static::saveModel($invoice->accountFrom, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $invoice->accountTo->amount += $invoice->amount;
        if (!static::saveModel($invoice->accountTo, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $invoice->status = $invoice::STATUS_SUCCESS;
        if (!static::saveModel($invoice, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $transaction->success();
        $dbTransact->commit();

        return true;
    }

    /**
     * Cancel an invoice
     *
     * @param Invoice $invoice invoice in status HOLD
     *
     * @return bool
     * @throws InvalidConfigException
     * @throws TransactionException
     * @throws \yii\db\Exception
     */
    public function cancel(ActiveRecord $invoice): bool
    {
        if (!$invoice instanceof $this->modelMap['Invoice']) {
            throw new InvalidConfigException('Invoice must be a class of ' . $this->modelMap['Invoice']);
        }

        if (!in_array($invoice->status, [$invoice::STATUS_HOLD, $invoice::STATUS_CREATE], true)) {
            $invoice->addError('status', 'Can\'t cancel finished invoice');

            return false;
        }

        /** @var Transaction $transactionClass */
        $transactionClass = $this->modelMap['Transaction'];
        $attributes = [
            'invoice_id' => $invoice->id,
            'type' => $transactionClass::TYPE_CANCEL,
        ];
        /** @var Transaction $transaction */
        $transaction = $transactionClass::create($attributes);

        $dbTransact = $this->db->beginTransaction();

        if ($invoice->status === $invoice::STATUS_HOLD) {
            $invoice->accountFrom->hold -= $invoice->amount;

            if (!static::saveModel($invoice->accountFrom, $invoice, $dbTransact, $transaction)) {
                return false;
            }
        }

        $invoice->status = $invoice::STATUS_CANCEL;
        if (!static::saveModel($invoice, $invoice, $dbTransact, $transaction)) {
            return false;
        }

        $transaction->success();
        $dbTransact->commit();

        return true;
    }

    /**
     * @param       $accountFrom
     * @param       $accountTo
     * @param float $amount
     *
     * @return ActiveRecord
     * @throws \yii\db\Exception
     * @throws InvalidConfigException
     */
    public function quickPay($accountFrom, $accountTo, float $amount): ActiveRecord
    {
        $invoice = $this->createInvoice($accountFrom, $accountTo, $amount);
        if ($invoice->hasErrors()) {
            return $invoice;
        }

        $this->hold($invoice);
        if ($invoice->hasErrors()) {
            return $invoice;
        }

        $this->finish($invoice);

        return $invoice;
    }

    /**
     * @param ActiveRecord  $model
     * @param Invoice       $invoice
     * @param DBTransaction $dbTransact
     * @param Transaction   $transaction
     *
     * @return bool
     * @throws \yii\db\Exception
     */
    protected static function saveModel(
        ActiveRecord $model,
        ActiveRecord $invoice,
        DBTransaction $dbTransact,
        ActiveRecord $transaction
    ): bool {
        if (!$model->save()) {
            if ($model !== $invoice) {
                $invoice->addLinkedErrors('accountFrom', $invoice->accountFrom);
            }

            $dbTransact->rollBack();
            $transaction->fail();

            return false;
        }

        return true;
    }

    protected function mapModels(): void
    {
        foreach ($this->modelMapDefault as $key => $model) {
            if (empty($this->modelMap[$key])) {
                $this->modelMap[$key] = $model;
            }
        }
    }

    protected function eventsAttach(): void
    {
        $account = $this->modelMap['Account'];
        Event::on(
            $account,
            $account::EVENT_BEFORE_VALIDATE,
            [EventHelper::class, 'accountBlackHoleZero'],
            ['blackHoleStrategy' => $this->blackHoleStrategy]
        );
    }
}
