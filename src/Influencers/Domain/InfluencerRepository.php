<?php

declare(strict_types=1);

namespace App\Influencers\Domain;

interface InfluencerRepository
{
    public function findById(string $id): ?Influencer;
    public function findByUserId(string $userId): ?Influencer;
    public function findByUsername(string $username): ?Influencer;
    public function save(Influencer $influencer): void;
    public function delete(Influencer $influencer): void;
}
