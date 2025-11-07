<?php

namespace Infofactory\StatamicAiTranslations\Controllers;

use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;
use Statamic\Facades\YAML;
use Statamic\Http\Controllers\Controller;
use Stillat\Proteus\Support\Facades\ConfigWriter;

class ConfigController extends Controller
{
    private function getProvidersOptions()
    {
        $providers_labels = collect([
            'anthropic' => 'Anthropic',
            'groq' => 'Groq',
            'mistral' => 'Mistral',
            'ollama' => 'Ollama',
            'openai' => 'OpenAI',
            'xai' => 'xAI',
        ]);

        $providers_options = [];

        // Load enabled providers
        foreach (config('prism.providers') as $provider => $provider_config) {
            $provider_config = collect($provider_config);
            $is_enabled = $provider_config->has('api_key') && $provider_config->get('api_key') || ! $provider_config->has('api_key');

            $label = $providers_labels->get($provider, Str::of($provider)->ucfirst());
            if (! $is_enabled) {
                $label .= ' ⚠️';
            }

            $providers_options[] = [
                'key' => $provider,
                'value' => $label,
                'enabled' => $is_enabled,
            ];
        }

        return $providers_options;
    }

    private function getBlueprint()
    {
        $config_yaml = YAML::file(__DIR__.'/../../resources/blueprint-templates/config.yaml')->parse();
        $config_yaml['tabs']['main']['sections'][0]['fields'][0]['field']['options'] = $this->getProvidersOptions();

        return Blueprint::make()->setContents($config_yaml);
    }

    public function edit()
    {
        $blueprint = $this->getBlueprint();
        $fields = $blueprint->fields()
            ->addValues(config('statamic-ai-translations'))
            ->preProcess();

        return view('statamic-ai-translations::edit', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta(),
            'values' => $fields->values(),
        ]);
    }

    public function update()
    {
        $blueprint = $this->getBlueprint();
        $fields = $blueprint->fields()->addValues(request()->all());
        $fields->validate();

        $data = $fields->process()->values()->toArray();

        ConfigWriter::writeMany('statamic-ai-translations', $data);
    }
}
