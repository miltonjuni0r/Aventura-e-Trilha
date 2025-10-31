document.addEventListener("DOMContentLoaded", () => {
  const botoesReserva = document.querySelectorAll(".botao-pacote");
  const painel = document.getElementById("painelReserva");
  const fechar = document.querySelector(".fechar");
  const nomePacote = document.getElementById("nomePacote");
  const precoPacote = document.getElementById("precoPacote");
  const confirmar = document.getElementById("confirmarReserva");

  // Abrir o painel de reserva ao clicar no botão
  botoesReserva.forEach(botao => {
    botao.addEventListener("click", () => {
      const pacote = botao.parentElement;
      const titulo = pacote.querySelector("h3").innerText;
      const preco = pacote.querySelector(".preco").innerText;

      nomePacote.innerText = `Pacote: ${titulo}`;
      precoPacote.innerText = `Preço: ${preco}`;
      painel.style.display = "flex";

      // Limpa campos e mensagens anteriores
      document.getElementById("nomeCliente").value = "";
      document.getElementById("telefone").value = "";
      document.getElementById("email").value = "";
      document.getElementById("cpf").value = "";

      const mensagem = document.getElementById("mensagemConfirmacao");
      if (mensagem) mensagem.innerText = "";
    });
  });

  // Fechar painel
  fechar.addEventListener("click", () => {
    painel.style.display = "none";
  });

  // Função para validar email básico
  function validarEmail(email) {
    return /\S+@\S+\.\S+/.test(email); // verifica se tem @ e .
  }

  // Função para validar CPF
  function validarCPF(cpf) {
    return /^\d{11}$/.test(cpf); // verifica se tem exatamente 11 dígitos
  }

  // Confirmar reserva
  confirmar.addEventListener("click", () => {
    const nome = document.getElementById("nomeCliente").value.trim();
    const tel = document.getElementById("telefone").value.trim();
    const email = document.getElementById("email").value.trim();
    const cpf = document.getElementById("cpf").value.trim();


    // Checa se todos os campos estão preenchidos
    if (!nome || !tel || !email || !cpf ) {
      alert("Por favor, preencha todos os campos antes de confirmar.");
      return;
    }

    // Valida email
    if (!validarEmail(email)) {
      alert("E-mail inválido! Certifique-se de incluir o '@' e domínio.");
      return;
    }

    // Valida CPF
    if (!validarCPF(cpf)) {
      alert("CPF inválido! O CPF deve ter exatamente 11 dígitos numéricos.");
      return;
    }

    // Exibe mensagem de sucesso
    let mensagem = document.getElementById("mensagemConfirmacao");
    if (!mensagem) {
      mensagem = document.createElement("p");
      mensagem.id = "mensagemConfirmacao";
      mensagem.style.color = "green";
      mensagem.style.fontWeight = "bold";
      painel.querySelector(".painel-conteudo").appendChild(mensagem);
    }

    mensagem.innerText = `✅ Pedido confirmado! Enviaremos mais informações no seu contato. Obrigado por reservar conosco, ${nome}!`;
  });
});
