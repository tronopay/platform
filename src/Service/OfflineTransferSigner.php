<?php

declare(strict_types=1);

namespace App\Service;

use App\ValueObject\SignedTransfer;
use App\ValueObject\UnsignedTransfer;
use IEXBase\TronAPI\Crypto\LocalPrivateKeySigner;
use IEXBase\TronAPI\Exception\TransactionException;
use IEXBase\TronAPI\Transaction\Transaction;
use IEXBase\TronAPI\Transaction\TransactionIntent;
use IEXBase\TronAPI\Transaction\TransactionSigner;
use IEXBase\TronAPI\Value\Address;

/**
 * Signs one unsigned escrow transfer. Runs only on the air-gapped machine.
 *
 * Before signing it rebuilds the {@see TransactionIntent} from the bundle's
 * business fields; {@see TransactionSigner} then re-encodes those bytes and
 * requires an exact match with the bundle's `raw_data_hex`. So a bundle whose
 * bytes do not match its stated recipient/amount/expiry is rejected, and the
 * key is only ever used for the address it was derived from.
 */
final readonly class OfflineTransferSigner
{
    public function __construct(
        private TransactionSigner $signer = new TransactionSigner(),
    ) {
    }

    public function sign(UnsignedTransfer $transfer, LocalPrivateKeySigner $signer): SignedTransfer
    {
        $derivedAddress = $signer->address()->toBase58();
        if ($derivedAddress !== $transfer->fromAddress) {
            throw new \RuntimeException(sprintf(
                'Refusing to sign: the derived key belongs to %s but the transfer is from %s.',
                $derivedAddress,
                $transfer->fromAddress,
            ));
        }

        $transaction = Transaction::fromNodeData([
            'raw_data' => $transfer->rawData,
            'raw_data_hex' => $transfer->rawDataHex,
            'txID' => $transfer->txId,
        ]);

        $intent = new TransactionIntent(
            'TransferContract',
            Address::fromBase58($transfer->fromAddress),
            [
                'to_address' => Address::fromBase58($transfer->toAddress),
                'amount' => $transfer->amountSun,
            ],
        );

        $signed = $this->signer->appendSignature($transaction, $signer, $intent);

        $signatures = $signed->signatures();
        $signature = $signatures[array_key_last($signatures)] ?? null;
        if ($signature === null) {
            throw new TransactionException('The signed transaction carries no signature.');
        }

        return new SignedTransfer(
            payload: $signed->toArray(),
            txId: $signed->id(),
            signature: $signature->toHex(),
            signerAddress: $signature->recoverAddress($signed->id())->toBase58(),
        );
    }
}
