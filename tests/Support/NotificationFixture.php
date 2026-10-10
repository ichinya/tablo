<?php
declare(strict_types=1);
namespace Tablo\Tests\Support;

use Tablo\Database;
use Tablo\NotificationSettings;
use Tablo\SiteRepository;
use Tablo\TokenVault;

final class NotificationFixture
{
    public readonly TemporaryDirectory $directory;
    public ?\PDO $db;
    public ?SiteRepository $sites;
    public ?TokenVault $vault;
    public int $now=1000;
    public int $id;
    public function __construct()
    {
        $this->directory=new TemporaryDirectory('tablo-notification-');
        $this->db=Database::connect($this->directory->path.'/db.sqlite');
        $this->vault=new TokenVault($this->directory->path.'/github-token.key');
        $this->sites=new SiteRepository($this->db,$this->vault,fn():int=>$this->now);
        $this->id=$this->sites->save(IncidentFixtures::site());
    }
    public function configure(array $extra=[]): void
    {
        $settings=new NotificationSettings($this->db,$this->vault);
        $settings->update($extra+['revision'=>(string)$settings->get()['revision'],'enabled'=>'1',
            'endpoint'=>'https://receiver.example/hook','unavailable'=>'1','recovery'=>'1','version_lag'=>'1']);
    }
    public function accept(?int $online,int $seconds,array $extra=[]): bool
    {
        $this->now=1000+$seconds;
        return IncidentFixtures::accept($this->sites,$this->id,$online,gmdate('Y-m-d\TH:i:s\Z',1767225600+$seconds),$extra);
    }
    public function slots(): array { return $this->db->query('SELECT * FROM notification_slots ORDER BY event')->fetchAll(); }
    public function close(): void { $this->sites=$this->vault=$this->db=null; $this->directory->close(); }
}
