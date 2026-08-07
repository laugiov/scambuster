<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Checksum;

use App\Application\Communication\Checksum\BitcoinAddress;
use App\Application\Communication\Checksum\EthereumAddress;
use App\Application\Communication\Checksum\Iban;
use PHPUnit\Framework\TestCase;

/**
 * Checksum validation for financial IOCs (IBAN mod-97, BTC bech32/Base58Check,
 * ETH EIP-55), against real-world / canonical vectors.
 */
final class FinancialChecksumTest extends TestCase
{
    /**
     * @dataProvider ibans
     */
    public function testIban(string $iban, bool $valid): void
    {
        self::assertSame($valid, Iban::isValid($iban));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function ibans(): array
    {
        return [
            'GB valid'          => ['GB82WEST12345698765432', true],
            'DE valid'          => ['DE89370400440532013000', true],
            'FR valid'          => ['FR1420041010050500013M02606', true],
            'NL valid'          => ['NL91ABNA0417164300', true],
            'GB spaced valid'   => ['GB82 WEST 1234 5698 7654 32', true],
            'GB bad check'      => ['GB82WEST12345698765431', false],
            'DE bad check'      => ['DE89370400440532013001', false],
            'not an iban'       => ['NOTANIBAN', false],
            'empty'             => ['', false],
        ];
    }

    /**
     * @dataProvider btcAddresses
     */
    public function testBitcoin(string $address, bool $valid): void
    {
        self::assertSame($valid, BitcoinAddress::isValid($address));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function btcAddresses(): array
    {
        return [
            'P2PKH genesis'   => ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', true],
            'P2PKH other'     => ['1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2', true],
            'P2SH'            => ['3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy', true],
            'bech32 P2WPKH'   => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4', true],
            'bech32 tb P2WSH' => ['tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7', true],
            // Taproot (BIP-350 bech32m — exercises the second checksum constant).
            'bech32m taproot' => ['bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6y', true],
            'taproot mutated' => ['bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6x', false],
            'P2PKH bad cksum' => ['1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb', false],
            'bech32 bad'      => ['bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5', false],
            'garbage'         => ['0OIl-not-base58', false],
        ];
    }

    /**
     * @dataProvider ethAddresses
     */
    public function testEthereum(string $address, bool $valid): void
    {
        self::assertSame($valid, EthereumAddress::isValid($address));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function ethAddresses(): array
    {
        return [
            // Official EIP-55 checksummed vectors.
            'eip55 a'      => ['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed', true],
            'eip55 b'      => ['0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359', true],
            'eip55 c'      => ['0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB', true],
            'eip55 d'      => ['0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb', true],
            'all lowercase' => ['0x5aaeb6053f3e94c9b9a09f33669435e7ef1beaed', true],
            'all uppercase' => ['0x5AAEB6053F3E94C9B9A09F33669435E7EF1BEAED', true],
            'bad checksum'  => ['0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAeD', false],
            'too short'     => ['0x5aAeb6053F3E94C9b9A09f3366', false],
        ];
    }
}
