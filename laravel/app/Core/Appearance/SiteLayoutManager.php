<?php

declare(strict_types=1);

namespace App\Core\Appearance;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

final class SiteLayoutManager
{
    private const HEADER_DEFAULTS=[
        'style'=>'standard',
        'sticky'=>true,
        'sections'=>[
            ['key'=>'brand','visible'=>true,'order'=>10],
            ['key'=>'navigation','visible'=>true,'order'=>20],
            ['key'=>'actions','visible'=>true,'order'=>30],
        ],
    ];

    private const FOOTER_DEFAULTS=[
        'columns'=>4,
        'show_payment_strip'=>true,
        'sections'=>[
            ['key'=>'brand','visible'=>true,'order'=>10],
            ['key'=>'links','visible'=>true,'order'=>20],
            ['key'=>'company','visible'=>true,'order'=>30],
            ['key'=>'contact','visible'=>true,'order'=>40],
        ],
    ];

    public function header(): array
    {
        $saved=$this->read('site.layout.header');
        return [
            'style'=>in_array($saved['style']??'',['standard','centered','compact'],true)?$saved['style']:'standard',
            'sticky'=>(bool)($saved['sticky']??true),
            'sections'=>$this->sections($saved['sections']??[],self::HEADER_DEFAULTS['sections']),
        ];
    }

    public function footer(): array
    {
        $saved=$this->read('site.layout.footer');
        return [
            'columns'=>in_array((int)($saved['columns']??4),[2,3,4],true)?(int)$saved['columns']:4,
            'show_payment_strip'=>(bool)($saved['show_payment_strip']??true),
            'sections'=>$this->sections($saved['sections']??[],self::FOOTER_DEFAULTS['sections']),
        ];
    }

    public function save(array $header,array $footer,?int $userId=null): void
    {
        $headerSafe=[
            'style'=>in_array($header['style']??'',['standard','centered','compact'],true)?$header['style']:'standard',
            'sticky'=>(bool)($header['sticky']??false),
            'sections'=>$this->sections($header['sections']??[],self::HEADER_DEFAULTS['sections']),
        ];
        $footerSafe=[
            'columns'=>in_array((int)($footer['columns']??4),[2,3,4],true)?(int)$footer['columns']:4,
            'show_payment_strip'=>(bool)($footer['show_payment_strip']??false),
            'sections'=>$this->sections($footer['sections']??[],self::FOOTER_DEFAULTS['sections']),
        ];
        foreach([$headerSafe,$footerSafe] as $config){
            if(!collect($config['sections'])->contains('visible',true)){
                abort(422,'At least one section must remain visible in each layout.');
            }
        }
        SystemSetting::query()->updateOrCreate(['key'=>'site.layout.header'],[
            'value'=>json_encode($headerSafe,JSON_THROW_ON_ERROR),'is_encrypted'=>false,'updated_by'=>$userId,
        ]);
        SystemSetting::query()->updateOrCreate(['key'=>'site.layout.footer'],[
            'value'=>json_encode($footerSafe,JSON_THROW_ON_ERROR),'is_encrypted'=>false,'updated_by'=>$userId,
        ]);
    }

    private function sections(mixed $incoming,array $defaults): array
    {
        $allowed=array_column($defaults,'key');
        $result=[];
        if(is_array($incoming)){
            foreach(array_slice($incoming,0,count($defaults)) as $item){
                if(!is_array($item)||!in_array($item['key']??'',$allowed,true))continue;
                $key=$item['key'];
                if(isset($result[$key]))continue;
                $result[$key]=[
                    'key'=>$key,
                    'visible'=>filter_var($item['visible']??false,FILTER_VALIDATE_BOOLEAN),
                    'order'=>max(1,min(100,(int)($item['order']??10))),
                ];
            }
        }
        foreach($defaults as $default){
            if(!isset($result[$default['key']]))$result[$default['key']]=$default;
        }
        $sections=array_values($result);
        usort($sections,static fn(array $a,array $b):int=>[$a['order'],$a['key']]<=>[$b['order'],$b['key']]);
        return $sections;
    }

    private function read(string $key): array
    {
        if(!Schema::hasTable('system_settings'))return [];
        $raw=SystemSetting::query()->where('key',$key)->value('value');
        $decoded=is_string($raw)?json_decode($raw,true):[];
        return is_array($decoded)?$decoded:[];
    }
}
