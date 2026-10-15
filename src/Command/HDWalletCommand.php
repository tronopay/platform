<?php

namespace App\Command;

use App\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use IEXBase\TronAPI\Crypto\Wallet\DerivationPath;
use IEXBase\TronAPI\Crypto\Wallet\ExtendedKey;
use IEXBase\TronAPI\Crypto\Wallet\HierarchicalWallet;
use IEXBase\TronAPI\Crypto\Wallet\MnemonicPhrase;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:hdw')]
class HDWalletCommand
{

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(env: 'string:TRON_XPUB_MASTER_KEY')]
        private string $xPub = '',
    )
    {
    }

    #[AsCommand('init')]
    public function init(
        InputInterface  $input,
        OutputInterface $output,
        #[\SensitiveParameter]
        #[Argument(description: 'A passphrase for the HDWallet master key.')]
        string          $passphrase = '',
    ): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('HD Wallet (Hierarchical Deterministic Wallet)');

        $hWallet = HierarchicalWallet::generate(passphrase: $passphrase);

        $io->section('Mnemonic for Master Private key (xPri):');
        $io->text($hWallet->mnemonic()->export());

        $io->section('Master Public key (xPub):');
        $io->text($hWallet->accountExtendedPublicKey());

        return Command::SUCCESS;
    }

    #[AsCommand('private')]
    public function private(
        InputInterface  $input,
        OutputInterface $output,
        #[\SensitiveParameter]
        #[Argument(description: 'A mnemonic for the HDWallet master key.')]
        string          $mnemonic,
        #[\SensitiveParameter]
        #[Option(description: 'A passphrase for the mnemonic of the HDWallet master key.')]
        string          $passphrase = '',
        #[Option]
        int             $index = 0,
    ): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('HD Wallet (Hierarchical Deterministic Wallet)');

        $hWallet = HierarchicalWallet::fromMnemonic(MnemonicPhrase::parse($mnemonic), $passphrase);

        $account = DerivationPath::tronAccount(index: $index);
        $first = $hWallet->account($account);

        $io->section('Wallet address:');
        $io->text($first->address()->toBase58());
        $io->section('Wallet private key:');
        $io->text($first->signer()->exportPrivateKey());

        return Command::SUCCESS;
    }

    #[AsCommand('address')]
    public function address(
        InputInterface  $input,
        OutputInterface $output,
        #[Argument]
        int             $index,
        #[\SensitiveParameter]
        #[Option(description: 'xPub master key the HDWallet.')]
        string          $xPub = '',
    ): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('HD Wallet (Hierarchical Deterministic Wallet)');

        $account = ExtendedKey::fromBase58($xPub ?: $this->xPub)->derivePath(sprintf('0/%d', $index));
        $io->section('Public key (address):');
        $io->text($account->address()->toBase58());

        return Command::SUCCESS;
    }

    #[AsCommand('fill')]
    public function fill(
        InputInterface  $input,
        OutputInterface $output,
        #[\SensitiveParameter]
        #[Option(description: 'xPub master key the HDWallet.')]
        string          $xPub = '',
    ): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('HD Wallet (Hierarchical Deterministic Wallet)');

        $account = ExtendedKey::fromBase58($xPub ?: $this->xPub);

        $count = 0;
        $wallets = $this->entityManager->getRepository(Wallet::class)->findBy(['address' => null]);
        foreach ($wallets as $wallet) {
            $newAddress = $account->derivePath(sprintf('0/%d', $wallet->getIndex()));
            $wallet->setAddress($newAddress->address()->toBase58());

            $this->entityManager->persist($wallet);
            $count++;
        }

        $this->entityManager->flush();
        $io->success('Update: ' . $count);

        return Command::SUCCESS;
    }
}
