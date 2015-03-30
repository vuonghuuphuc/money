<?php
/*
 * This file is part of the Money package.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SebastianBergmann\Money;

/**
 * Value Object that represents a monetary value
 * (using a currency's smallest unit).
 *
 * @package    Money
 * @author     Sebastian Bergmann <sebastian@phpunit.de>
 * @copyright  Sebastian Bergmann <sebastian@phpunit.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://www.github.com/sebastianbergmann/money
 * @see        http://martinfowler.com/bliki/ValueObject.html
 * @see        http://martinfowler.com/eaaCatalog/money.html
 */
class Money implements \JsonSerializable
{
    /**
     * @var int
     */
    private $amount;

    /**
     * @var \SebastianBergmann\Money\Currency
     */
    private $currency;

    /**
     * @var int[]
     */
    private static $roundingModes = [
        PHP_ROUND_HALF_UP,
        PHP_ROUND_HALF_DOWN,
        PHP_ROUND_HALF_EVEN,
        PHP_ROUND_HALF_ODD
    ];

    /**
     * @param  int                                  $amount
     * @param  \SebastianBergmann\Money\Currency|string $currency
     * @throws \SebastianBergmann\Money\InvalidArgumentException
     */
    public function __construct(int $amount, $currency)
    {
        $this->amount   = $amount;
        $this->currency = $this->handleCurrencyArgument($currency);
    }

    /**
     * Creates a Money object from a string such as "12.34"
     *
     * This method is designed to take into account the errors that can arise
     * from manipulating floating point numbers.
     *
     * If the number of decimals in the string is higher than the currency's
     * number of fractional digits then the value will be rounded to the
     * currency's number of fractional digits.
     *
     * @param  string                                   $value
     * @param  \SebastianBergmann\Money\Currency|string $currency
     * @return static
     * @throws \SebastianBergmann\Money\InvalidArgumentException
     */
    public static function fromString(string $value, $currency) : Money
    {
        $currency = self::handleCurrencyArgument($currency);

        return new static(
            intval(
                round(
                    $currency->getSubUnit() *
                    round(
                        $value,
                        $currency->getDefaultFractionDigits(),
                        PHP_ROUND_HALF_UP
                    ),
                    0,
                    PHP_ROUND_HALF_UP
                )
            ),
            $currency
        );
    }

    /**
     * Specify data which should be serialized to JSON
     *
     * @return array data which can be serialized by <b>json_encode</b>,
     * @link   http://php.net/manual/en/jsonserializable.jsonserialize.php
     */
    public function jsonSerialize() : array
    {
        return [
            'amount'   => $this->amount,
            'currency' => $this->currency->getCurrencyCode()
        ];
    }

    /**
     * Returns the monetary value represented by this object.
     *
     * @return int
     */

    public function getAmount() : int
    {
        return $this->amount;
    }

    /**
     * return the monetary value represented by this object converted to its base units
     *
     * @return float
     */

    public function getConvertedAmount() : float
    {
        return round($this->amount / $this->currency->getSubUnit(), $this->currency->getDefaultFractionDigits());
    }

    /**
     * Returns the currency of the monetary value represented by this
     * object.
     *
     * @return \SebastianBergmann\Money\Currency
     */
    public function getCurrency() : Currency
    {
        return $this->currency;
    }

    /**
     * Returns a new Money object that represents the monetary value
     * of the sum of this Money object and another.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return static
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     * @throws \SebastianBergmann\Money\OverflowException
     */
    public function add(Money $other) : Money
    {
        $this->assertSameCurrency($this, $other);

        $value = $this->amount + $other->getAmount();

        $this->assertIsInteger($value);

        return $this->newMoney($value);
    }

    /**
     * Returns a new Money object that represents the monetary value
     * of the difference of this Money object and another.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return static
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     * @throws \SebastianBergmann\Money\OverflowException
     */
    public function subtract(Money $other) : Money
    {
        $this->assertSameCurrency($this, $other);

        $value = $this->amount - $other->getAmount();

        $this->assertIsInteger($value);

        return $this->newMoney($value);
    }

    /**
     * Returns a new Money object that represents the negated monetary value
     * of this Money object.
     *
     * @return static
     */
    public function negate() : Money
    {
        return $this->newMoney(-1 * $this->amount);
    }

    /**
     * Returns a new Money object that represents the monetary value
     * of this Money object multiplied by a given factor.
     *
     * @param  float $factor
     * @param  int   $roundingMode
     * @return static
     * @throws \SebastianBergmann\Money\InvalidArgumentException
     */
    public function multiply(float $factor, int $roundingMode = PHP_ROUND_HALF_UP) : Money
    {
        return $this->newMoney(
            $this->castToInt(
                round($factor * $this->amount, 0, $roundingMode)
            )
        );
    }

    /**
     * Allocate the monetary value represented by this Money object
     * among N targets.
     *
     * @param  int $n
     * @return static[]
     * @throws \SebastianBergmann\Money\InvalidArgumentException
     */
    public function allocateToTargets(int $n) : array
    {
        $low       = $this->newMoney(intval($this->amount / $n));
        $high      = $this->newMoney($low->getAmount() + 1);
        $remainder = $this->amount % $n;
        $result    = [];

        for ($i = 0; $i < $remainder; $i++) {
            $result[] = $high;
        }

        for ($i = $remainder; $i < $n; $i++) {
            $result[] = $low;
        }

        return $result;
    }

    /**
     * Allocate the monetary value represented by this Money object
     * using a list of ratios.
     *
     * @param  array $ratios
     * @return static[]
     */
    public function allocateByRatios(array $ratios) : array
    {
        /** @var \SebastianBergmann\Money\Money[] $result */
        $result    = [];
        $total     = array_sum($ratios);
        $remainder = $this->amount;

        for ($i = 0; $i < count($ratios); $i++) {
            $amount     = $this->castToInt($this->amount * $ratios[$i] / $total);
            $result[]   = $this->newMoney($amount);
            $remainder -= $amount;
        }

        for ($i = 0; $i < $remainder; $i++) {
            $result[$i] = $this->newMoney($result[$i]->getAmount() + 1);
        }

        return $result;
    }

    /**
     * Extracts a percentage of the monetary value represented by this Money
     * object and returns an array of two Money objects:
     * $original = $result['subtotal'] + $result['percentage'];
     *
     * Please note that this extracts the percentage out of a monetary value
     * where the percentage is already included. If you want to get the
     * percentage of the monetary value you should use multiplication
     * (multiply(0.21), for instance, to calculate 21% of a monetary value
     * represented by a Money object) instead.
     *
     * @param  float $percentage
     * @param  int   $roundingMode
     * @return static[]
     * @see    https://github.com/sebastianbergmann/money/issues/27
     */
    public function extractPercentage(float $percentage, $roundingMode = PHP_ROUND_HALF_UP) : array
    {
        $percentage = $this->newMoney(
            $this->castToInt(
                round($this->amount / (100 + $percentage) * $percentage, 0, $roundingMode)
            )
        );

        return [
            'percentage' => $percentage,
            'subtotal'   => $this->subtract($percentage)
        ];
    }

    /**
     * Compares this Money object to another.
     *
     * Returns an integer less than, equal to, or greater than zero
     * if the value of this Money object is considered to be respectively
     * less than, equal to, or greater than the other Money object.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return int -1|0|1
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function compareTo(Money $other) : int
    {
        $this->assertSameCurrency($this, $other);

        if ($this->amount == $other->getAmount()) {
            return 0;
        }

        return $this->amount < $other->getAmount() ? -1 : 1;
    }

    /**
     * Returns TRUE if this Money object equals to another.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return bool
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function equals(Money $other) : bool
    {
        return $this->compareTo($other) == 0;
    }

    /**
     * Returns TRUE if the monetary value represented by this Money object
     * is greater than that of another, FALSE otherwise.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return bool
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function greaterThan(Money $other) : bool
    {
        return $this->compareTo($other) == 1;
    }

    /**
     * Returns TRUE if the monetary value represented by this Money object
     * is greater than or equal that of another, FALSE otherwise.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return bool
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function greaterThanOrEqual(Money $other) : bool
    {
        return $this->greaterThan($other) || $this->equals($other);
    }

    /**
     * Returns TRUE if the monetary value represented by this Money object
     * is smaller than that of another, FALSE otherwise.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return bool
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function lessThan(Money $other) : bool
    {
        return $this->compareTo($other) == -1;
    }

    /**
     * Returns TRUE if the monetary value represented by this Money object
     * is smaller than or equal that of another, FALSE otherwise.
     *
     * @param  \SebastianBergmann\Money\Money $other
     * @return bool
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    public function lessThanOrEqual(Money $other) : bool
    {
        return $this->lessThan($other) || $this->equals($other);
    }

    /**
     * @param  \SebastianBergmann\Money\Money $a
     * @param  \SebastianBergmann\Money\Money $b
     * @throws \SebastianBergmann\Money\CurrencyMismatchException
     */
    private function assertSameCurrency(Money $a, Money $b)
    {
        if ($a->getCurrency() != $b->getCurrency()) {
            throw new CurrencyMismatchException;
        }
    }

    /**
     * Raises an exception if the amount is not an integer
     *
     * @param  number $amount
     * @return number
     * @throws \SebastianBergmann\Money\OverflowException
     */
    private function assertIsInteger($amount)
    {
        if (!is_int($amount)) {
            throw new OverflowException;
        }
    }// @codeCoverageIgnore

    /**
     * Raises an exception if the amount is outside of the integer bounds
     *
     * @param  number $amount
     * @return number
     * @throws \SebastianBergmann\Money\OverflowException
     */
    private function assertInsideIntegerBounds($amount)
    {
        if (abs($amount) > PHP_INT_MAX) {
            throw new OverflowException;
        }
    }// @codeCoverageIgnore

    /**
     * Cast an amount to an integer but ensure that the operation won't hide overflow
     *
     * @param number $amount
     * @return int
     * @throws \SebastianBergmann\Money\OverflowException
     */
    private function castToInt($amount) : int
    {
        $this->assertInsideIntegerBounds($amount);

        return intval($amount);
    }

    /**
     * @param  int $amount
     * @return static
     */
    private function newMoney($amount) : Money
    {
        return new static($amount, $this->currency);
    }

    /**
     * @param  \SebastianBergmann\Money\Currency|string $currency
     * @return \SebastianBergmann\Money\Currency
     * @throws \SebastianBergmann\Money\InvalidArgumentException
     */
    private static function handleCurrencyArgument($currency) : Currency
    {
        if (!$currency instanceof Currency && !is_string($currency)) {
            throw new InvalidArgumentException('$currency must be an object of type Currency or a string');
        }

        if (is_string($currency)) {
            $currency = new Currency($currency);
        }

        return $currency;
    }
}
