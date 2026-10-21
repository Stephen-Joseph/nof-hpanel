<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case PENDING = 'pending';
    case FAILED = 'failed';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::PENDING => 'Issuing / Pending',
            self::FAILED => 'Failed',
            self::REVOKED => 'Revoked',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ACTIVE => 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
            self::EXPIRED => 'bg-rose-500/10 text-rose-400 border border-rose-500/20',
            self::PENDING => 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
            self::FAILED => 'bg-rose-500/10 text-rose-400 border border-rose-500/20',
            self::REVOKED => 'bg-slate-500/10 text-slate-400 border border-slate-500/20',
        };
    }
}
