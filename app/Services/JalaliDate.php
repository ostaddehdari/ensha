<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class JalaliDate
{
    public const MONTHS=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

    public function fromGregorian(DateTimeInterface|string $date): array
    {
        $date=$date instanceof DateTimeInterface?$date:Carbon::parse($date);
        $gy=(int)$date->format('Y'); $gm=(int)$date->format('n'); $gd=(int)$date->format('j');
        $gdm=[0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2=$gm>2?$gy+1:$gy;
        $days=355666+(365*$gy)+(int)(($gy2+3)/4)-(int)(($gy2+99)/100)+(int)(($gy2+399)/400)+$gd+$gdm[$gm-1];
        $jy=-1595+33*(int)($days/12053); $days%=12053;
        $jy+=4*(int)($days/1461); $days%=1461;
        if($days>365){ $jy+=(int)(($days-1)/365); $days=($days-1)%365; }
        $jm=$days<186?1+(int)($days/31):7+(int)(($days-186)/30);
        $jd=1+($days<186?$days%31:($days-186)%30);
        return [$jy,$jm,$jd];
    }

    public function toGregorian(int $jy,int $jm,int $jd): array
    {
        $jy+=1595; $days=-355668+(365*$jy)+(int)($jy/33)*8+(int)((($jy%33)+3)/4)+$jd+($jm<7?($jm-1)*31:(($jm-7)*30)+186);
        $gy=400*(int)($days/146097); $days%=146097;
        if($days>36524){ $gy+=100*(int)(--$days/36524); $days%=36524; if($days>=365)$days++; }
        $gy+=4*(int)($days/1461); $days%=1461;
        if($days>365){ $gy+=(int)(($days-1)/365); $days=($days-1)%365; }
        $gd=$days+1; $sal=[0,31,($gy%4===0&&$gy%100!==0)||$gy%400===0?29:28,31,30,31,30,31,31,30,31,30,31];
        for($gm=1;$gm<=12&&$gd>$sal[$gm];$gm++) $gd-=$sal[$gm];
        return [$gy,$gm,$gd];
    }

    public function format(DateTimeInterface|string $date,string $separator='/'): string
    {
        [$y,$m,$d]=$this->fromGregorian($date);
        return sprintf('%04d%s%02d%s%02d',$y,$separator,$m,$separator,$d);
    }

    public function monthKey(DateTimeInterface|string $date): string
    {
        [$y,$m]=$this->fromGregorian($date);
        return sprintf('%04d-%02d',$y,$m);
    }

    public function monthLabel(string $key): string
    {
        [$year,$month]=array_map('intval',explode('-',$key));
        return (self::MONTHS[$month-1]??$month).' '.$year;
    }

    public function currentMonthRange(): array
    {
        [$jy,$jm]=$this->fromGregorian(now());
        [$sy,$sm,$sd]=$this->toGregorian($jy,$jm,1);
        $ny=$jm===12?$jy+1:$jy; $nm=$jm===12?1:$jm+1;
        [$ey,$em,$ed]=$this->toGregorian($ny,$nm,1);
        return [Carbon::create($sy,$sm,$sd)->startOfDay(),Carbon::create($ey,$em,$ed)->subDay()->endOfDay(),sprintf('%04d-%02d',$jy,$jm)];
    }
}
