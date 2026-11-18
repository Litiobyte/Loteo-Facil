import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { runInNewContext } from 'node:vm'

const livewire = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8')
const start = livewire.indexOf('  var regexes = {', livewire.indexOf('// node_modules/@alpinejs/mask/'))
const end = livewire.indexOf('  var module_default10', start)
assert.ok(start >= 0 && end > start, 'Installed Alpine mask implementation must be available')
const { formatMoney, formatInput } = runInNewContext(
    `${livewire.slice(start, end)}; ({ formatMoney, formatInput })`,
    { queueMicrotask: () => {} },
)

const component = readFileSync(new URL('../../app/Filament/Forms/ChileanNumberInput.php', import.meta.url), 'utf8')
const masks = [...component.matchAll(/RawJs::make\('([^']+)'\)/g)]
    .map((match) => match[1].replaceAll('\\\\', '\\'))
assert.equal(masks.length, 2)

function mask(value, surface = false) {
    const template = runInNewContext(masks[surface ? 1 : 0], {
        $input: value,
        $money: formatMoney.bind({ el: { value } }),
    })
    return template ? formatInput(template, value) : value
}

test('money masks typed and pasted thousands and millions without decimals', () => {
    for (const [input, expected] of [
        ['4', '4'],
        ['1000', '1.000'],
        ['1000000', '1.000.000'],
        ['1.000.000', '1.000.000'],
        ['0', '0'],
        ['-1000000', '-1.000.000'],
    ]) {
        assert.equal(mask(input), expected)
    }
})

test('invalid money input is not silently converted into a different amount', () => {
    for (const input of ['1,5', '1e6', '1.000,25']) {
        assert.equal(mask(input), input)
    }
})

test('surface masks group thousands while preserving decimal comma', () => {
    for (const [input, expected] of [
        ['1000', '1.000'],
        ['1000,125', '1.000,125'],
        ['1.000,125', '1.000,125'],
        ['1000000,25', '1.000.000,25'],
        ['0,0001', '0,0001'],
    ]) {
        assert.equal(mask(input, true), expected)
    }
})
