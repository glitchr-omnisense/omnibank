<?php

namespace Omnibank\Model;

/**
 * What an account is, whatever the provider calls it: each provider maps its
 * own words onto these (Account::$type keeps the word as the provider wrote
 * it). A word a provider has not published, or that its mapping does not
 * know yet, is UNKNOWN - never an error.
 */
enum AccountKind: string
{
    /** A current account: cards and transfers are paid from it. */
    case CHECKING = 'checking';
    /** A savings account money can be taken from at any time (livret A, LDDS, CSL...). */
    case SAVINGS = 'savings';
    /** Money locked until a date (compte à terme). */
    case TERM_DEPOSIT = 'term_deposit';
    /** A card's own account: a deferred debit card's outstanding. */
    case CARD = 'card';
    /** A loan: a mortgage, a business loan. */
    case LOAN = 'loan';
    /** A consumer credit or personal loan. */
    case CONSUMER_CREDIT = 'consumer_credit';
    /** A revolving credit or line of credit. */
    case REVOLVING = 'revolving';
    /** A securities account (compte-titres). */
    case MARKET = 'market';
    case LIFE_INSURANCE = 'life_insurance';
    /** A savings plan with rules: PEA, PEE, PEL, CEL, a capitalisation contract. */
    case SAVINGS_PLAN = 'savings_plan';
    /** A retirement plan: PER, PERCO, PERP, Madelin, article 83. */
    case RETIREMENT = 'retirement';
    /** Another investment: real estate placements, crowdlending. */
    case OTHER_INVESTMENT = 'other_investment';
    case UNKNOWN = 'unknown';

    /**
     * The family a dashboard groups it in: "cash" (checking, card), "savings"
     * (savings, term deposits), "investment" (market, life insurance, plans,
     * retirement, others), "credit" (loans, consumer and revolving credits),
     * "unknown".
     */
    public function group(): string
    {
        return match ($this) {
            self::CHECKING, self::CARD => 'cash',
            self::SAVINGS, self::TERM_DEPOSIT => 'savings',
            self::MARKET, self::LIFE_INSURANCE, self::SAVINGS_PLAN, self::RETIREMENT, self::OTHER_INVESTMENT => 'investment',
            self::LOAN, self::CONSUMER_CREDIT, self::REVOLVING => 'credit',
            self::UNKNOWN => 'unknown',
        };
    }

    /** What the account holder owes rather than owns: its balance counts against the total. */
    public function isLiability(): bool
    {
        return 'credit' === $this->group();
    }

    /** From a value stored earlier (the enum's value); UNKNOWN for anything else. */
    public static function of(?string $value): self
    {
        return null === $value ? self::UNKNOWN : (self::tryFrom(strtolower($value)) ?? self::UNKNOWN);
    }
}
