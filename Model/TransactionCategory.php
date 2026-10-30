<?php

namespace Omnibank\Model;

/**
 * What a transaction was for, whatever the provider calls it: a short list
 * every provider's categories map onto (its own words stay in the
 * transaction's $raw). A transaction a provider did not categorise has none
 * (null); a category its mapping does not know is UNKNOWN - never an error.
 */
enum TransactionCategory: string
{
    /** Salaries, pensions, benefits, rents received, sales, interest. */
    case INCOME = 'income';
    /** Rent paid, energy, water, the home's charges. */
    case HOUSING = 'housing';
    /** The home's running: furniture, repairs, cleaning, childcare, schooling, pets. */
    case HOUSEHOLD = 'household';
    /** Groceries, restaurants, bars. */
    case FOOD = 'food';
    /** Fuel, public transport, trains, planes, parking, tolls, vehicle upkeep. */
    case TRANSPORT = 'transport';
    /** Clothes, electronics, books, online shopping. */
    case SHOPPING = 'shopping';
    /** Doctors, pharmacy, mutual health insurance. */
    case HEALTH = 'health';
    /** Outings, sport, holidays, hotels, culture, gifts to associations. */
    case LEISURE = 'leisure';
    /** Phone, internet, TV, media and software subscriptions. */
    case SUBSCRIPTIONS = 'subscriptions';
    case INSURANCE = 'insurance';
    /** Taxes, fines, social contributions. */
    case TAXES = 'taxes';
    /** What the bank charges: account fees, incidents, foreign exchange. */
    case BANK_FEES = 'bank_fees';
    /** Loan repayments. */
    case LOAN = 'loan';
    /** Money put aside or invested. */
    case SAVINGS = 'savings';
    /** A business's own spending: accounting, legal, marketing, office, suppliers. */
    case BUSINESS = 'business';
    /** Transfers between people or accounts, whatever they pay. */
    case TRANSFER = 'transfer';
    /** Cash withdrawn or deposited, cheques. */
    case CASH = 'cash';
    /** What the provider itself files as "other". */
    case OTHER = 'other';
    /** A category the provider gave that its mapping does not know. */
    case UNKNOWN = 'unknown';

    /** From a value stored earlier (the enum's value): null for none, UNKNOWN for anything else. */
    public static function of(?string $value): ?self
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return self::tryFrom(strtolower($value)) ?? self::UNKNOWN;
    }
}
