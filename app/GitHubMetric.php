<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubMetric
{
    public function __construct(private readonly GitHubRequestPolicy $policy, private readonly GitHubCredential $credential, private readonly string $identity) {}
    public function metric(string $key, \Closure $load): mixed
    {
        try {
            $this->credential->assertCurrent($this->policy, $this->identity);
            $result = $this->policy->remember($this->identity, $key, function () use ($load): mixed {
                $value = $load();
                $this->credential->assertCurrent($this->policy, $this->identity);
                return $value;
            });
            $this->credential->assertCurrent($this->policy, $this->identity);
            return $result;
        } catch (GitHubFailure | \PDOException | SharedKeyFailure $error) { throw $error; }
        catch (\Throwable) { throw new GitHubFailure('unavailable'); }
    }
}
