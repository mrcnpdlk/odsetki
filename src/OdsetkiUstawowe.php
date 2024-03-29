<?php
/**
 * Created by Marcin.
 * Date: 17.10.2020
 * Time: 21:27
 */

namespace Mrcnpdlk\Lib\Odsetki;

use Mrcnpdlk\Lib\Odsetki\Model\RangeModel;
use Nette\Neon\Neon;

/**
 * Class Odsetki
 */
class OdsetkiUstawowe
{
    /**
     * @var \Mrcnpdlk\Lib\Odsetki\Date
     */
    private Date $deadlineDate;
    /**
     * @var \Mrcnpdlk\Lib\Odsetki\Date
     */
    private Date $paymentDate;
    /**
     * @var float
     */
    private float $charge;
    /**
     * @var \Mrcnpdlk\Lib\Odsetki\Model\RangeModel[]
     */
    private array $ranges;
    /**
     * @var mixed[]
     */
    private array $tDesc = [];

    /**
     * Odsetki constructor.
     *
     * @param string $deadlineDate
     * @param string $paymentDate
     * @param float  $charge
     *
     * @throws \Mrcnpdlk\Lib\Odsetki\Exception
     */
    public function __construct(string $deadlineDate, string $paymentDate, float $charge = 1.0)
    {
        try {
            $tConfig = Neon::decode(file_get_contents(__DIR__ . '/../config.neon'));
        } catch (\Nette\Neon\Exception $e) {
            throw new Exception('Cannot parse config file', 0, $e);
        }

        $this->deadlineDate = Date::parse($deadlineDate);
        /*
         * Jeżeli dzień ustawowo wolny od pracy to weź pierwszy wolny
         */
        if ($this->deadlineDate->isFreeDay()) {
            $this->deadlineDate = $this->deadlineDate->getNextWorkingDay();
        }

        $this->paymentDate = Date::parse($paymentDate);
        $this->charge      = $charge;

        $tRanges = $tConfig['rates'];
        /*
         * Sortujemy po dacie od najmniejszej
         */
        ksort($tRanges);
        $keys = array_keys($tRanges);
        /**
         * Minimalna zdefiniowana data
         *
         * @see https://stackoverflow.com/questions/5096791/get-next-element-in-foreach-loop
         */
        $minDate = Date::parse($keys[0]);
        foreach (array_keys($keys) as $index) {
            $currentKey     = current($keys);
            $currentValue   = $tRanges[$currentKey];
            $nextKey        = next($keys);
            $this->ranges[] = new RangeModel(
                Date::parse($currentKey),
                false === $nextKey ? Date::parse('2099-12-31') : Date::parse($nextKey)->addDays(-1),
                $currentValue
            );
        }

        if ($minDate->gt($this->deadlineDate)) {
            throw new Exception(sprintf('Data terminu zapłaty %s jest mniejsza niż zdefiniowany początkowy zakres wartości odsetek %s', $this->deadlineDate->getAsStr(), $minDate->getAsStr()));
        }
    }

    /**
     * @return float
     */
    public function calculate(): float
    {
        /*
         * Jeżeli ten sam dzień to odsetki równe 0
         *
         * @see https://ksiegowosc.infor.pl/podatki/ordynacja-podatkowa/3604139,Rok-przestepny-w-podatkach.html
         */
        if ($this->deadlineDate === $this->paymentDate) {
            return 0.0;
        }
        if ($this->deadlineDate->gt($this->paymentDate)) {
            return 0.0;
        }
        $calcSum     = 0.0;
        $this->tDesc = [];
        foreach ($this->ranges as $range) {
            if ($this->deadlineDate->gt($range->to) || $this->paymentDate->lt($range->from)) {
                continue;
            }
            $desc = [];
            if ($range->has($this->deadlineDate)) {
                if ($range->has($this->paymentDate)) {
                    $diffDay = $this->paymentDate->diff($this->deadlineDate);
                    $desc[]  = $this->deadlineDate->getAsStr();
                    $desc[]  = $this->paymentDate->getAsStr();
                } else {
                    $diffDay = $range->to->diff($this->deadlineDate);
                    $desc[]  = $this->deadlineDate->getAsStr();
                    $desc[]  = $range->to->getAsStr();
                }
            } else {
                if ($range->has($this->paymentDate)) {
                    $diffDay = $this->paymentDate->diff($range->from) + 1;
                    $desc[]  = $range->from->getAsStr();
                    $desc[]  = $this->paymentDate->getAsStr();
                } else {
                    $diffDay = $range->to->diff($range->from) + 1;
                    $desc[]  = $range->from->getAsStr();
                    $desc[]  = $range->to->getAsStr();
                }
            }

            $delta = round($range->percent * $diffDay * $this->charge / 365, 2);

            $desc[]        = $diffDay;
            $desc[]        = $range->percent;
            $desc[]        = $delta;
            $calcSum       += $delta;
            $this->tDesc[] = $desc;
        }

        return round($calcSum, 2);
    }

    /**
     * @return mixed[]
     */
    public function getDesc(): array
    {
        return $this->tDesc;
    }
}
